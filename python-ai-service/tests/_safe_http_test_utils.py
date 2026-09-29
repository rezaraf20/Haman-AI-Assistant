"""
Shared test helper for anything that used to patch product_tools._requests.post
directly. _query_live() now goes through safe_http.safe_post(), which does its
own DNS resolution before the HTTP call — the synthetic domains these
fixtures use (shop.example.com) don't really resolve, so that lookup must be
faked too, not just the HTTP call itself, and at its new location
(app.services.safe_http.requests.post, not product_tools._requests.post).
"""
import socket
from contextlib import contextmanager
from unittest.mock import patch


@contextmanager
def fake_live_query(**post_kwargs):
    """Drop-in replacement for the old
    `patch("app.services.tools.product_tools._requests.post", **post_kwargs)`.
    Fakes DNS resolution to a real public IP (so safe_http's SSRF validation
    doesn't refuse the synthetic domain) and patches the HTTP call at its
    actual current location."""
    with patch("socket.getaddrinfo", return_value=[(socket.AF_INET, socket.SOCK_STREAM, 6, "", ("93.184.216.34", 0))]), \
         patch("app.services.safe_http.requests.post", **post_kwargs) as mock_post:
        yield mock_post
