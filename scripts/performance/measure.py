#!/usr/bin/env python3
"""Measure authenticated HTTP latency on a dedicated synthetic tenant; never log payloads."""
import argparse
import http.cookiejar
import json
import math
import os
import time
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timezone, date, timedelta
from html.parser import HTMLParser


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class InertiaPage(HTMLParser):
    def __init__(self):
        super().__init__()
        self.page = None

    def handle_starttag(self, tag, attrs):
        attributes = dict(attrs)
        if attributes.get('id') == 'app' and 'data-page' in attributes:
            self.page = json.loads(attributes['data-page'])


def percentile95(values):
    if not values:
        raise ValueError('No samples')
    return sorted(values)[math.ceil(len(values) * 0.95) - 1]



def validate_fixture(fixture, allow_small=False):
    days, prompts = fixture['days'], fixture['prompts']
    expected = days * prompts * 18
    if not 1 <= days <= 365 or not 1 <= prompts <= 100:
        raise ValueError('Invalid fixture dimensions')
    if not allow_small and (days != 365 or prompts != 100):
        raise ValueError('Acceptance requires 365 days and 100 prompts')
    counts = {'engines': 6, 'repetitions': 3, 'runs': expected, 'responses': expected,
              'citations': expected, 'mentions': days * prompts * 12,
              'agg_daily': days * prompts * 6, 'agg_source_daily': days * 6,
              'aggregated_responses': expected}
    if any(fixture[key] != value for key, value in counts.items()):
        raise ValueError('Incomplete fixture or aggregates')
    end = date.fromisoformat(fixture['to'])
    if end != datetime.now(timezone.utc).date() or date.fromisoformat(fixture['from']) != end - timedelta(days=days - 1):
        raise ValueError('Stale fixture; dashboards end today')
    return days, prompts


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('origin')
    parser.add_argument('--samples', type=int, default=100)
    parser.add_argument('--warmup', type=int, default=5)
    parser.add_argument('--report', default='performance-result.json')
    parser.add_argument('--fixture', required=True, help='JSON from fixture.php verify')
    parser.add_argument('--allow-small-fixture', action='store_true', help='Local harness validation only')
    parser.add_argument('--dashboard-only', action='store_true', help='Partial run while REST is unavailable')
    args = parser.parse_args()
    origin = urllib.parse.urlsplit(args.origin)
    if (origin.scheme != 'https' and not (origin.scheme == 'http' and origin.hostname in ['127.0.0.1', 'localhost'])) or origin.path not in ['', '/'] or origin.username or origin.password or origin.query or origin.fragment:
        raise ValueError('HTTPS origin or local loopback required')
    if not 20 <= args.samples <= 1000 or not 0 <= args.warmup <= 20:
        raise ValueError('Use 20–1000 samples and 0–20 warmups')
    base = args.origin.rstrip('/')
    email, password = (os.environ[key] for key in ['PERF_EMAIL', 'PERF_PASSWORD'])
    token = None if args.dashboard_only else os.environ['PERF_REST_TOKEN']
    with open(args.fixture) as source:
        fixture = json.load(source)
    days, prompts = validate_fixture(fixture, args.allow_small_fixture)
    brand = int(fixture['brand_id'])
    if brand < 1:
        raise ValueError('Positive brand ID required')
    jar = http.cookiejar.CookieJar()
    web = urllib.request.build_opener(NoRedirect(), urllib.request.HTTPCookieProcessor(jar))
    api = urllib.request.build_opener(NoRedirect())

    def call(opener, path, headers=None, data=None):
        req = urllib.request.Request(base + path, data=data, headers=headers or {})
        start = time.perf_counter()
        with opener.open(req, timeout=30) as response:
            body = response.read()
            if response.status != 200:
                raise ValueError('Unexpected HTTP status')
        return body, (time.perf_counter() - start) * 1000

    call(web, '/login')
    xsrf = next(cookie.value for cookie in jar if cookie.name == 'XSRF-TOKEN')
    call(web, '/login', {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': urllib.parse.unquote(xsrf)}, json.dumps({'email': email, 'password': password}).encode())
    routes = [('/dashboard', 'Home'), *[(f'/brands/{brand}?days={window}', 'Brands/Show') for window in [7, 28, 90]]]
    if not args.dashboard_only:
        routes.append((f'/api/v1/brands/{brand}/metrics', None))
    result = {'measured_at': datetime.now(timezone.utc).isoformat(), 'samples_per_route': args.samples, 'warmups_per_route': args.warmup, 'threshold_ms': 800, 'mode': 'serial authenticated HTTP; full response; warm service', 'origin': base, 'fixture': fixture, 'partial': args.dashboard_only or args.allow_small_fixture, 'routes': []}
    for path, component in routes:
        durations = []
        for number in range(args.warmup + args.samples):
            body, elapsed = call(web if component else api, path, None if component else {'Accept': 'application/json', 'Authorization': 'Bearer ' + token})
            if component:
                page = InertiaPage()
                page.feed(body.decode())
                if not page.page or page.page.get('component') != component:
                    raise ValueError('Unexpected page; redirects and login pages are not samples')
                props = page.page['props']
                if component == 'Home':
                    row = next(row for row in props['brands'] if row['id'] == brand)
                    n = row['visibility']['n']
                else:
                    if props['brand']['id'] != brand:
                        raise ValueError('Wrong fixture brand')
                    n = props['summary']['visibility']['n']
                if n != min(days, props['period']['days']) * prompts * 18:
                    raise ValueError('Metrics do not contain the expected fixture volume')
            elif not isinstance(json.loads(body).get('data', {}).get('summary', {}).get('visibility', {}).get('n'), int):
                raise ValueError('Invalid metrics contract')
            if number >= args.warmup:
                durations.append(elapsed)
            if component is None:
                # Keep the measured request time separate from token-limit pacing.
                time.sleep(max(0, 1.1 - elapsed / 1000))
        p95 = percentile95(durations)
        result['routes'].append({'route': path, 'p95_ms': round(p95, 2), 'min_ms': round(min(durations), 2), 'max_ms': round(max(durations), 2), 'passed': p95 < 800})
    with open(args.report, 'w') as report:
        json.dump(result, report, indent=2)
        report.write('\n')
    print(json.dumps(result))
    return 0 if all(row['passed'] for row in result['routes']) else 1


if __name__ == '__main__':
    try:
        raise SystemExit(main())
    except (KeyError, ValueError, TypeError, StopIteration, OSError, urllib.error.URLError, TimeoutError):
        raise SystemExit('Measurement failed: verify test identity, target, HTTP status and fixture. No credentials or response bodies logged.')
