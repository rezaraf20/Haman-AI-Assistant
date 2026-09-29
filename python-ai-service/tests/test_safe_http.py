"""
SSRF hardening for safe_http.safe_post — the shared client _query_live()
(product_tools.py) now goes through instead of a bare requests.post(). Real
IP literals from every blocked category, plus the DNS-rebinding defense
tested directly at the mechanism level: a pinned lookup must return the
already-validated address even when the "real" resolver would now answer
something else entirely.
"""
import socket
import unittest
from unittest.mock import MagicMock, patch

from app.services import safe_http
from app.services.safe_http import MAX_REDIRECTS, SafeHttpError, _is_blocked_ip, _resolve_safe_ip, safe_post


def _fake_getaddrinfo(answers):
    def _fn(host, *args, **kwargs):
        return [(socket.AF_INET, socket.SOCK_STREAM, 6, "", (ip, 0)) for ip in answers]
    return _fn


class BlockedIpTest(unittest.TestCase):
    def test_private_ipv4_ranges_are_blocked(self):
        for ip in ("10.0.0.1", "10.255.255.254", "172.16.0.1", "172.31.255.254", "192.168.0.1", "192.168.255.254"):
            self.assertTrue(_is_blocked_ip(ip), ip)

    def test_loopback_is_blocked(self):
        self.assertTrue(_is_blocked_ip("127.0.0.1"))
        self.assertTrue(_is_blocked_ip("127.255.255.255"))
        self.assertTrue(_is_blocked_ip("::1"))

    def test_link_local_is_blocked(self):
        self.assertTrue(_is_blocked_ip("169.254.1.1"))
        self.assertTrue(_is_blocked_ip("fe80::1"))

    def test_cloud_metadata_address_is_blocked(self):
        # 169.254.169.254 (AWS/GCP/Azure instance metadata) — a link-local
        # address, but called out on its own since it's the one real-world
        # address this defense exists for.
        self.assertTrue(_is_blocked_ip("169.254.169.254"))

    def test_unspecified_and_multicast_are_blocked(self):
        self.assertTrue(_is_blocked_ip("0.0.0.0"))
        self.assertTrue(_is_blocked_ip("224.0.0.1"))
        self.assertTrue(_is_blocked_ip("::"))
        self.assertTrue(_is_blocked_ip("ff02::1"))

    def test_ipv4_mapped_ipv6_private_addresses_are_blocked(self):
        # A private IPv4 address wrapped in IPv6 notation is still that
        # private address — must be judged as such, not as a "public" v6 literal.
        self.assertTrue(_is_blocked_ip("::ffff:127.0.0.1"))
        self.assertTrue(_is_blocked_ip("::ffff:169.254.169.254"))
        self.assertTrue(_is_blocked_ip("::ffff:10.0.0.1"))

    def test_ipv6_unique_local_is_blocked(self):
        self.assertTrue(_is_blocked_ip("fc00::1"))
        self.assertTrue(_is_blocked_ip("fd12:3456:789a::1"))

    def test_real_public_addresses_are_not_blocked(self):
        for ip in ("8.8.8.8", "1.1.1.1", "93.184.216.34", "2606:4700:4700::1111"):
            self.assertFalse(_is_blocked_ip(ip), ip)


class ResolveSafeIpTest(unittest.TestCase):
    def test_resolves_to_the_only_public_answer(self):
        with patch("socket.getaddrinfo", _fake_getaddrinfo(["93.184.216.34"])):
            self.assertEqual(_resolve_safe_ip("example.com"), "93.184.216.34")

    def test_refuses_a_host_with_even_one_private_answer_among_several(self):
        with patch("socket.getaddrinfo", _fake_getaddrinfo(["93.184.216.34", "10.0.0.1"])):
            with self.assertRaises(SafeHttpError):
                _resolve_safe_ip("example.com")

    def test_dns_failure_raises_safe_http_error(self):
        def _raise(*a, **k):
            raise socket.gaierror("nope")
        with patch("socket.getaddrinfo", _raise):
            with self.assertRaises(SafeHttpError):
                _resolve_safe_ip("nowhere.invalid")


class PinnedDnsGuardTest(unittest.TestCase):
    """Direct tests of the DNS-rebinding defense: the guard must never let
    a pinned hostname be re-resolved for real, no matter what the actual
    resolver would now answer."""

    def test_a_pinned_lookup_returns_the_pinned_ip(self):
        with patch.object(safe_http, "_real_getaddrinfo") as real:
            real.return_value = [(socket.AF_INET, socket.SOCK_STREAM, 6, "", ("203.0.113.9", 443))]
            with safe_http._pinned("shop.example.com", "203.0.113.9"):
                result = socket.getaddrinfo("shop.example.com", 443)
            real.assert_called_once_with("203.0.113.9", 443)
        self.assertEqual(result[0][4][0], "203.0.113.9")

    def test_a_rebinding_attempt_never_reaches_the_real_resolver_for_the_hostname(self):
        # Even if the real resolver would now answer a completely different
        # (and unsafe) address for this hostname, it is never asked to —
        # the guard substitutes the pinned IP and never re-resolves the name.
        with patch.object(safe_http, "_real_getaddrinfo") as real:
            real.return_value = [(socket.AF_INET, socket.SOCK_STREAM, 6, "", ("169.254.169.254", 443))]
            with safe_http._pinned("shop.example.com", "203.0.113.9"):
                socket.getaddrinfo("shop.example.com", 443)
            real.assert_called_once_with("203.0.113.9", 443)

    def test_a_lookup_for_an_unexpected_host_during_a_pinned_request_is_refused(self):
        with safe_http._pinned("shop.example.com", "203.0.113.9"):
            with self.assertRaises(SafeHttpError):
                socket.getaddrinfo("evil.example.com", 443)

    def test_unpinned_lookups_are_unaffected(self):
        with patch.object(safe_http, "_real_getaddrinfo", return_value="unrelated") as real:
            result = socket.getaddrinfo("anything.example.com", 443)
        self.assertEqual(result, "unrelated")
        real.assert_called_once_with("anything.example.com", 443)

    def test_the_pin_is_cleared_after_the_context_exits_even_on_error(self):
        with self.assertRaises(ValueError):
            with safe_http._pinned("shop.example.com", "203.0.113.9"):
                raise ValueError("boom")
        self.assertIsNone(getattr(safe_http._pin_state, "pin", None))


class SafePostTest(unittest.TestCase):
    def test_a_successful_request_is_returned(self):
        with patch("socket.getaddrinfo", _fake_getaddrinfo(["93.184.216.34"])), \
             patch("app.services.safe_http.requests.post") as post:
            resp = MagicMock(status_code=200)
            post.return_value = resp
            result = safe_post("https://example.com/x", data=b"{}", headers={}, timeout=5)
        self.assertIs(result, resp)
        self.assertEqual(post.call_args.kwargs["allow_redirects"], False)

    def test_a_request_to_a_blocked_ip_literal_is_refused_before_any_http_call(self):
        with patch("app.services.safe_http.requests.post") as post:
            with self.assertRaises(SafeHttpError):
                safe_post("http://169.254.169.254/latest/meta-data/", data=b"{}", headers={}, timeout=5)
        post.assert_not_called()

    def test_a_redirect_is_followed_after_revalidating_the_new_host(self):
        with patch("socket.getaddrinfo", _fake_getaddrinfo(["93.184.216.34"])), \
             patch("app.services.safe_http.requests.post") as post:
            redirect = MagicMock(status_code=302, headers={"Location": "https://example.com/final"})
            final = MagicMock(status_code=200)
            post.side_effect = [redirect, final]
            result = safe_post("https://example.com/start", data=b"{}", headers={}, timeout=5)
        self.assertIs(result, final)
        self.assertEqual(post.call_count, 2)

    def test_a_redirect_into_a_blocked_address_is_refused(self):
        def fake_resolve(host, *a, **k):
            ip = "169.254.169.254" if host == "internal.example.com" else "93.184.216.34"
            return [(socket.AF_INET, socket.SOCK_STREAM, 6, "", (ip, 0))]
        with patch("socket.getaddrinfo", fake_resolve), \
             patch("app.services.safe_http.requests.post") as post:
            redirect = MagicMock(status_code=302, headers={"Location": "http://internal.example.com/steal"})
            post.return_value = redirect
            with self.assertRaises(SafeHttpError):
                safe_post("https://example.com/start", data=b"{}", headers={}, timeout=5)

    def test_too_many_redirects_is_refused(self):
        with patch("socket.getaddrinfo", _fake_getaddrinfo(["93.184.216.34"])), \
             patch("app.services.safe_http.requests.post") as post:
            redirect = MagicMock(status_code=302, headers={"Location": "https://example.com/next"})
            post.return_value = redirect
            with self.assertRaises(SafeHttpError):
                safe_post("https://example.com/start", data=b"{}", headers={}, timeout=5)
        self.assertEqual(post.call_count, MAX_REDIRECTS + 1)

    def test_an_unsupported_scheme_is_refused(self):
        with patch("app.services.safe_http.requests.post") as post:
            with self.assertRaises(SafeHttpError):
                safe_post("file:///etc/passwd", data=b"{}", headers={}, timeout=5)
        post.assert_not_called()


if __name__ == "__main__":
    unittest.main()
