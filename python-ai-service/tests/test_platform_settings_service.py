"""
platform_settings_service.py had no dedicated tests before this -- added
alongside the shared-Redis-key audit, since CACHE_KEY is one of only two
keys that genuinely cross languages (Laravel's Settings.php invalidates
it) and the other one's missing "shared:" marker is exactly what made its
invalidation a silent no-op for as long as it existed.
"""
import json
import unittest
from unittest.mock import MagicMock, patch

from app.services import platform_settings_service as pss


class CacheKeyConventionTest(unittest.TestCase):
    def test_cache_key_carries_the_shared_marker(self):
        """Laravel's Settings.php can only invalidate this key through
        Redis::connection('shared') because of this exact prefix -- see
        SharedRedisKeyConventionTest.php on the PHP side."""
        self.assertTrue(pss.CACHE_KEY.startswith("shared:"))


class LoadTest(unittest.TestCase):
    def test_a_cache_hit_never_touches_the_database(self):
        db = MagicMock()
        with patch.object(pss, "_redis") as fake_redis:
            fake_redis.get.return_value = json.dumps({"limits.pdf_max_mb": 25})
            values = pss._load(db)
        self.assertEqual(values, {"limits.pdf_max_mb": 25})
        db.execute.assert_not_called()

    def test_a_cache_miss_reads_the_database_and_refills_the_cache(self):
        db = MagicMock()
        db.execute.return_value.first.return_value = ['{"limits.pdf_max_mb": 7}']
        with patch.object(pss, "_redis") as fake_redis:
            fake_redis.get.return_value = None
            values = pss._load(db)
            fake_redis.setex.assert_called_once()
            self.assertEqual(fake_redis.setex.call_args[0][0], pss.CACHE_KEY)
        self.assertEqual(values, {"limits.pdf_max_mb": 7})

    def test_a_non_dict_column_value_falls_back_to_empty_not_a_crash(self):
        db = MagicMock()
        db.execute.return_value.first.return_value = ["not even json {"]
        with patch.object(pss, "_redis") as fake_redis:
            fake_redis.get.return_value = None
            values = pss._load(db)
        self.assertEqual(values, {})


class GetTest(unittest.TestCase):
    def test_unknown_key_raises(self):
        db = MagicMock()
        with self.assertRaises(KeyError):
            pss.get(db, "limits.not_a_real_setting")

    def test_missing_value_returns_the_declared_default(self):
        db = MagicMock()
        with patch.object(pss, "_load", return_value={}):
            self.assertEqual(pss.get(db, "limits.pdf_max_mb"), pss.DEFAULTS["limits.pdf_max_mb"])

    def test_a_non_numeric_value_falls_back_to_the_default(self):
        db = MagicMock()
        with patch.object(pss, "_load", return_value={"limits.pdf_max_mb": "a lot"}):
            self.assertEqual(pss.get(db, "limits.pdf_max_mb"), pss.DEFAULTS["limits.pdf_max_mb"])

    def test_a_bool_is_rejected_even_though_it_is_technically_an_int_subclass(self):
        db = MagicMock()
        with patch.object(pss, "_load", return_value={"limits.pdf_max_mb": True}):
            self.assertEqual(pss.get(db, "limits.pdf_max_mb"), pss.DEFAULTS["limits.pdf_max_mb"])


if __name__ == "__main__":
    unittest.main()
