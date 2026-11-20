import { rmSync } from 'node:fs';
export default function teardown() {
    rmSync('.ui-auth', { recursive: true, force: true });
}
