// The only module that imports ECharts. EkkoChart loads it with import(), so Vite puts the
// library in its own chunk and pages without charts never download it (docs/ui-forbedring.md 5).
// Register only what the chart components use: tree shaking keeps the rest out of the bundle.
import { init, use } from 'echarts/core';
import { SVGRenderer } from 'echarts/renderers';
import { BarChart, HeatmapChart, LineChart, ScatterChart, TreemapChart } from 'echarts/charts';
import {
    GridComponent,
    LegendComponent,
    MarkAreaComponent,
    MarkLineComponent,
    TooltipComponent,
} from 'echarts/components';

use([
    SVGRenderer,
    LineChart,
    BarChart,
    HeatmapChart,
    ScatterChart,
    TreemapChart,
    GridComponent,
    LegendComponent,
    MarkAreaComponent,
    MarkLineComponent,
    TooltipComponent,
]);

export { init };
