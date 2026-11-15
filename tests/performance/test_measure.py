import importlib.util
from pathlib import Path
import unittest

spec = importlib.util.spec_from_file_location('measure', Path(__file__).parents[2] / 'scripts/performance/measure.py')
measure = importlib.util.module_from_spec(spec)
spec.loader.exec_module(measure)


class MeasureTest(unittest.TestCase):
    def test_nearest_rank_p95(self):
        self.assertEqual(measure.percentile95(list(range(1, 101))), 95)
        self.assertEqual(measure.percentile95(list(range(20, 0, -1))), 19)
        with self.assertRaises(ValueError):
            measure.percentile95([])

    def test_rejects_partial_or_stale_dataset(self):
        end = measure.datetime.now(measure.timezone.utc).date()
        fixture = {'days': 365, 'prompts': 100, 'engines': 6, 'repetitions': 3,
                   'runs': 657000, 'responses': 657000, 'citations': 657000,
                   'mentions': 438000, 'agg_daily': 219000, 'agg_source_daily': 2190,
                   'aggregated_responses': 657000, 'to': end.isoformat(),
                   'from': (end - measure.timedelta(days=364)).isoformat()}
        self.assertEqual(measure.validate_fixture(fixture), (365, 100))
        for key, value in [('responses', 656999), ('aggregated_responses', 0), ('to', '2020-01-01')]:
            with self.subTest(key=key), self.assertRaises(ValueError):
                measure.validate_fixture({**fixture, key: value})

    def test_inertia_page_decodes_html_attribute(self):
        page = measure.InertiaPage()
        page.feed('<div id="app" data-page="{&quot;component&quot;:&quot;Home&quot;}"></div>')
        self.assertEqual(page.page['component'], 'Home')


if __name__ == '__main__':
    unittest.main()
