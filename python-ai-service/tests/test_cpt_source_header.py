"""
Custom WordPress post types (a "portfolio" item, a "service", a project) are
synced by Laravel as source_type 'wordpress_cpt', with metadata type 'cpt',
the type's label and the item's URL. For a corporate site, "this is one of
our portfolio projects, here's the link" is part of the answer -- and the
model can only give a link it can actually see in the context.

These check what this code puts in front of the model: the context label
carries the type and the link for cpt chunks only, and both language
variants of the grounding rules tell the model to say what the item is and
to quote that link verbatim rather than build one. Whether a live model
then does so is checked against the real server, not here.
"""
import unittest

from app.services.rag_service import _source_header, GROUNDING_RULES, GROUNDING_RULES_FA


class SourceHeaderTest(unittest.TestCase):
    def test_a_custom_post_type_chunk_carries_its_type_and_link(self):
        header = _source_header(3, {
            "title": "سایت فروشگاه کفش",
            "type": "cpt",
            "post_type": "portfolio",
            "post_type_label": "نمونه کار",
            "url": "https://hamantech.ir/portfolio/shoe-shop/",
        })
        self.assertEqual(header, "[Source 3] سایت فروشگاه کفش (نمونه کار) <https://hamantech.ir/portfolio/shoe-shop/>")

    def test_the_type_slug_stands_in_when_no_label_was_synced(self):
        header = _source_header(1, {"title": "X", "type": "cpt", "post_type": "service", "url": "https://a.test/s"})
        self.assertEqual(header, "[Source 1] X (service) <https://a.test/s>")

    def test_pages_and_pdfs_keep_the_label_they_always_had(self):
        # A page has a url in its metadata too, but its label must not change:
        # nothing about how page answers are phrased was asked to change.
        self.assertEqual(_source_header(2, {"title": "About", "type": "page", "url": "https://a.test/about"}), "[Source 2] About")
        self.assertEqual(_source_header(4, {"title": "Datasheet.pdf", "page": 7}), "[Source 4] Datasheet.pdf (page 7)")
        self.assertEqual(_source_header(5, {}), "[Source 5]")

    def test_both_rule_sets_tell_the_model_to_name_the_item_and_quote_its_link(self):
        self.assertIn("one of our portfolio projects", GROUNDING_RULES)
        self.assertIn("give its link exactly as written", GROUNDING_RULES)
        self.assertIn("Never build or guess a link", GROUNDING_RULES)
        self.assertIn("این یکی از نمونه کارهای ماست", GROUNDING_RULES_FA)
        self.assertIn("لینکش را دقیقاً همان‌طور", GROUNDING_RULES_FA)


if __name__ == "__main__":
    unittest.main()
