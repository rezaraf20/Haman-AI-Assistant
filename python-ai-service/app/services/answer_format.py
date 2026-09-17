"""
Take the internal source labels back out of a customer-facing answer.

The retrieved passages are handed to the model headed "[Source 1] Title",
"[Source 2] Datasheet.pdf (page 4)" and so on, so that it can cite a file and
page when the answer comes from a technical document. Those brackets are
scaffolding for the model, not something a customer should ever read -- and a
real conversation came back with "تیم ما بیش از ۸ متخصص باتجربه است
[Source 1, Source 4]".

The prompt now says so, and this removes whatever says it anyway. Both,
because the prompt already asked for a readable citation ("according to
Datasheet.pdf, page 4") and got the raw label instead.

Only the label goes. A real citation the model wrote in words is left alone:
naming the file and page is the behaviour these sources exist for.
"""
import re

# "[Source 1]", "[Source 1, Source 4]", "[source 2]", "[منبع ۱]" -- and the
# same with a page: "[Source 2] (page 4)" is produced as one label by the
# context builder, so the trailing part is matched with it.
_LABEL = re.compile(
    r"\[\s*(?:source|منبع)\s*\d+"           # the first one
    r"(?:\s*,\s*(?:source|منبع)\s*\d+)*"    # any repeats inside one bracket
    r"\s*\]",
    re.IGNORECASE,
)

# A label usually arrives with a space in front and punctuation behind:
# "است [Source 1]." must not become "است ." once the label is gone.
_SPACE_BEFORE_PUNCT = re.compile(r"[ \t]+([.،,؛;:!?])")
_RUNS_OF_SPACE = re.compile(r"[ \t]{2,}")
_SPACE_BEFORE_NEWLINE = re.compile(r"[ \t]+\n")


def strip_source_labels(text: str) -> tuple[str, int]:
    """Return the text without internal source labels, and how many were cut."""
    if not text:
        return text or "", 0

    cleaned, count = _LABEL.subn("", text)
    if not count:
        return text, 0

    # Tidy the gap the label left behind rather than leaving stranded
    # punctuation, which reads as a typo to the customer.
    cleaned = _SPACE_BEFORE_PUNCT.sub(r"\1", cleaned)
    cleaned = _RUNS_OF_SPACE.sub(" ", cleaned)
    cleaned = _SPACE_BEFORE_NEWLINE.sub("\n", cleaned)

    return cleaned.strip(), count
