"""The http/https rule for live queries to a merchant's shop.

A live query carries a shop's stock and prices and is signed with its webhook
secret, so it must never leave the public internet in the clear. The rule is
therefore deliberately narrow: http only where the host provably cannot be a
real shop. The test that matters most is the last one — a public domain can
never be downgraded, because a hijacked primary_domain would otherwise become
a way to strip TLS off every live query.

Must stay in step with LiveQueryClient::scheme() on the PHP side, which
reaches the same endpoint.
"""
import unittest

from app.services.tools.product_tools import _scheme


class LiveQuerySchemeTest(unittest.TestCase):

    def test_a_bare_hostname_is_internal(self):
        # A Docker service name has no dot and cannot be resolved publicly.
        self.assertEqual(_scheme("testshop"), "http")
        self.assertEqual(_scheme("wordpress"), "http")

    def test_private_use_tlds_are_internal(self):
        for host in ("shop.test", "wp.local", "site.localhost", "box.internal", "nope.invalid"):
            self.assertEqual(_scheme(host), "http", host)

    def test_private_ipv4_literals_are_internal(self):
        for host in ("127.0.0.1", "10.1.2.3", "192.168.0.7", "172.16.0.1", "172.31.255.254"):
            self.assertEqual(_scheme(host), "http", host)

    def test_public_domains_always_get_https(self):
        for host in ("khonehrangi.ir", "example.com", "shop.example.co.uk", "hamantech.ir"):
            self.assertEqual(_scheme(host), "https", host)

    def test_addresses_just_outside_the_private_ranges_are_public(self):
        # 172.16/12 is the private block: .15 and .32 are outside it, and a
        # sloppy prefix match would wrongly downgrade them.
        for host in ("172.15.0.1", "172.32.0.1", "11.0.0.1", "126.0.0.1", "193.168.0.1"):
            self.assertEqual(_scheme(host), "https", host)

    def test_a_public_domain_cannot_be_downgraded_by_decoration(self):
        # Whatever is appended, the host is still public.
        for host in ("evil.com:8080", "evil.com/path", "EVIL.COM", "evil.com:80/x"):
            self.assertEqual(_scheme(host), "https", host)

    def test_a_private_suffix_inside_a_public_domain_does_not_count(self):
        # Ends with "test" as a label boundary only when it really is the TLD.
        self.assertEqual(_scheme("mytest.com"), "https")
        self.assertEqual(_scheme("test.com"), "https")
        self.assertEqual(_scheme("local.example.com"), "https")


if __name__ == "__main__":
    unittest.main()
