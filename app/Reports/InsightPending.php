<?php

namespace App\Reports;

use RuntimeException;

/** Another run already claimed this period's insight and has not stored the text yet. */
final class InsightPending extends RuntimeException {}
