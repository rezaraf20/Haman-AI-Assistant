"""
The plugin's old spelling, as far as this service still has to know it.

The product is Haman, one "m". The WordPress plugin shipped as "Hamman"
through 1.9.0, which fixed the old spelling into the REST namespace this
service calls for live stock and price.

The platform and each customer's plugin update independently and in either
order, so a site still on 1.9.0 answers only the old path. Asking for the new
one and stopping there would turn every live stock and price question on that
shop into "I can't confirm that right now".

TODO(2027-03-01): delete this module and its call sites, once no site is on
1.x. Mirrors PluginLegacy on the Laravel side and Haman_Legacy in the plugin,
which carry the same date.

This is deliberately the only Python file that contains the old spelling.
"""

#: The 1.x REST namespace, tried after the current one returns 404.
REST_NAMESPACE = "hamman/v1"

#: The 1.x HMAC header. Kept for symmetry with the other two shims -- this
#: service only makes outbound calls, so nothing reads it today.
SIGNATURE_HEADER = "X-Hamman-Signature"

#: The current namespace first, the old one as the fallback.
NAMESPACES = ("haman/v1", REST_NAMESPACE)
