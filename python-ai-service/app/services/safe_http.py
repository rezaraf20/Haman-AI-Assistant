"""
A shared, SSRF-hardened POST client.

Every outbound call this service makes to a merchant-configured or otherwise
untrusted hostname (today: product_tools.py's live-query calls to a tenant's
own WordPress site) belongs on this, not a bare requests.post() — Phase 7's
custom action/tool builder will open a much bigger version of the same
surface and is meant to be built on this same client, not a copy of it.

What it does that requests.post() alone does not:
  - Resolves the hostname and validates every address it returns is not
    private, loopback, link-local (this is also where the 169.254.169.254
    cloud-metadata address falls), multicast, reserved, or unspecified —
    for both IPv4 and IPv6 (including an IPv6-mapped IPv4 address).
  - Pins the actual TCP connection to the one address that was validated,
    so a second DNS lookup mid-request (DNS rebinding: the first answer is
    safe, a later one for the same name is not) can never hand back a
    different, unvalidated address. The original hostname is still what's
    sent in the URL/Host header and used for TLS SNI/certificate
    verification — only the address the socket actually connects to is
    substituted.
  - Re-resolves and re-validates every redirect hop the same way, up to a
    small cap, instead of following a validated request straight into an
    unvalidated redirect target.
"""
import ipaddress
import logging
import socket
import threading
from contextlib import contextmanager
from typing import Optional
from urllib.parse import urljoin, urlparse

import requests

logger = logging.getLogger(__name__)

MAX_REDIRECTS = 3
_REDIRECT_STATUS_CODES = (301, 302, 303, 307, 308)


class SafeHttpError(Exception):
    """Refused before or during connection — never a signal to retry with
    less validation, only to give up or report the failure upward."""


def _is_blocked_ip(ip_str: str) -> bool:
    addr = ipaddress.ip_address(ip_str)
    # An IPv4 address expressed as ::ffff:a.b.c.d must be judged as the
    # IPv4 address it actually is, not as a "public" IPv6 literal.
    mapped = getattr(addr, "ipv4_mapped", None)
    if mapped is not None:
        addr = mapped
    return (
        addr.is_private
        or addr.is_loopback
        or addr.is_link_local
        or addr.is_multicast
        or addr.is_reserved
        or addr.is_unspecified
    )


def _resolve_safe_ip(hostname: str) -> str:
    """Every address this hostname resolves to must be safe — refused
    outright the moment even one answer is blocked, since nothing here
    controls which answer a load-balanced or future lookup would actually
    hand a real connection."""
    try:
        infos = socket.getaddrinfo(hostname, None)
    except socket.gaierror as e:
        raise SafeHttpError(f"DNS resolution failed for {hostname}: {e}")

    ips = {info[4][0] for info in infos}
    if not ips:
        raise SafeHttpError(f"DNS resolution returned no addresses for {hostname}")

    for ip in ips:
        if _is_blocked_ip(ip):
            raise SafeHttpError(f"{hostname} resolves to a blocked address ({ip})")

    # A deterministic pick among the (now all-safe) answers, so the address
    # validated above is the exact one pinned and connected to below.
    return sorted(ips)[0]


# Installed once, globally, at import time. With no pin active for the
# calling thread it behaves exactly like the real socket.getaddrinfo, so
# every other DNS lookup in this process (Postgres, Redis, the Gemini/Groq
# SDKs, anything else) is entirely unaffected.
_real_getaddrinfo = socket.getaddrinfo
_pin_state = threading.local()


def _guarded_getaddrinfo(host, *args, **kwargs):
    pinned = getattr(_pin_state, "pin", None)
    if pinned is None:
        return _real_getaddrinfo(host, *args, **kwargs)

    pinned_host, pinned_ip = pinned
    if not isinstance(host, str) or host.lower() != pinned_host.lower():
        # A pinned request resolving some OTHER hostname mid-flight is not
        # a normal thing to happen — fail closed rather than silently
        # letting an unvalidated lookup through.
        raise SafeHttpError(f"Unexpected DNS lookup for {host!r} during a pinned request to {pinned_host!r}")
    return _real_getaddrinfo(pinned_ip, *args, **kwargs)


socket.getaddrinfo = _guarded_getaddrinfo


@contextmanager
def _pinned(hostname: str, ip: str):
    _pin_state.pin = (hostname, ip)
    try:
        yield
    finally:
        _pin_state.pin = None


def safe_post(url: str, *, data: bytes, headers: dict, timeout: float) -> requests.Response:
    """POST with SSRF hardening — see the module docstring. Raises
    SafeHttpError instead of connecting when the target (or a redirect
    target) is not safe; raises requests.RequestException for an ordinary
    network failure, same as a plain requests.post() would."""
    for _ in range(MAX_REDIRECTS + 1):
        parsed = urlparse(url)
        if parsed.scheme not in ("http", "https"):
            raise SafeHttpError(f"Unsupported scheme in {url!r}")
        hostname = parsed.hostname
        if not hostname:
            raise SafeHttpError(f"No hostname in {url!r}")

        ip = _resolve_safe_ip(hostname)
        with _pinned(hostname, ip):
            resp = requests.post(url, data=data, headers=headers, timeout=timeout, allow_redirects=False)

        if resp.status_code in _REDIRECT_STATUS_CODES:
            location = resp.headers.get("Location")
            if not location:
                return resp
            url = urljoin(url, location)
            continue

        return resp

    raise SafeHttpError(f"Too many redirects (> {MAX_REDIRECTS}) for the original request")
