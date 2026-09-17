"""
Internal source labels must not reach a customer.

The context is headed "[Source 1] Title" so the model can cite a file and
page when the answer comes from a technical document. A real conversation
came back with "تیم ما بیش از ۸ متخصص باتجربه است [Source 1, Source 4]" --
the customer cannot see any numbered list, so the label says nothing to them.

The prompt now forbids it; this is the second layer, for when it does it
anyway. A citation written in words is left alone, because that is the
behaviour the page numbers exist for.
"""
import unittest

from app.services.answer_format import strip_source_labels


class StripSourceLabelsTest(unittest.TestCase):
    def test_the_sentence_from_the_reported_conversation(self):
        text = "تیم ما بیش از ۸ متخصص باتجربه است [Source 1, Source 4]."

        cleaned, count = strip_source_labels(text)

        self.assertEqual(count, 1)
        self.assertEqual(cleaned, "تیم ما بیش از ۸ متخصص باتجربه است.")

    def test_a_label_per_bullet(self):
        text = (
            "* طراحی وب‌سایت واکنش‌گرا [Source 1]\n"
            "* سرعت بالا [Source 1]\n"
            "* سئو-محور [Source 1]"
        )

        cleaned, count = strip_source_labels(text)

        self.assertEqual(count, 3)
        self.assertNotIn("Source", cleaned)
        self.assertIn("* سرعت بالا", cleaned)
        # No line should be left ending in a stray space.
        for line in cleaned.split("\n"):
            self.assertEqual(line, line.rstrip())

    def test_punctuation_closes_up_behind_the_label(self):
        cleaned, _ = strip_source_labels("زمان اجرا ۲ تا ۸ هفته است [Source 1].")

        self.assertEqual(cleaned, "زمان اجرا ۲ تا ۸ هفته است.")
        self.assertNotIn(" .", cleaned)

    def test_variants(self):
        for text in ("answer [Source 2]", "answer [source 2]", "answer [ Source 2 ]",
                     "answer [منبع ۲]".replace("۲", "2"), "answer [Source 1, Source 2, Source 3]"):
            cleaned, count = strip_source_labels(text)
            self.assertEqual(count, 1, text)
            self.assertEqual(cleaned, "answer", text)

    def test_a_citation_written_in_words_survives(self):
        # This is what the prompt asks for on a technical document, and it is
        # useful to the customer -- unlike the bracketed label.
        text = "طبق Datasheet.pdf، صفحه ۴، ولتاژ ورودی ۵ ولت است."

        cleaned, count = strip_source_labels(text)

        self.assertEqual(count, 0)
        self.assertEqual(cleaned, text)

    def test_ordinary_brackets_are_not_touched(self):
        for text in ("قیمت (بدون مالیات) ۱۰۰ تومان است.",
                     "see [the guide] for details",
                     "array[0] holds the first item"):
            cleaned, count = strip_source_labels(text)
            self.assertEqual(count, 0, text)
            self.assertEqual(cleaned, text, text)

    def test_empty_input_is_safe(self):
        self.assertEqual(strip_source_labels(""), ("", 0))
        self.assertEqual(strip_source_labels(None), ("", 0))


if __name__ == "__main__":
    unittest.main()
