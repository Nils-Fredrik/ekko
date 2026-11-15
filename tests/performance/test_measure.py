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

    def test_inertia_page_decodes_html_attribute(self):
        page = measure.InertiaPage()
        page.feed('<div id="app" data-page="{&quot;component&quot;:&quot;Home&quot;}"></div>')
        self.assertEqual(page.page['component'], 'Home')


if __name__ == '__main__':
    unittest.main()
