/*
 * Minimal local renderer for the sketch files in this folder (*.dc.html).
 *
 * The sketches were made in Claude Design, whose own runtime is not part of this repository.
 * This file supports the subset the sketches use, so they render when opened in a browser:
 * {{path}} holes in text and attributes, <sc-for list as>, <sc-if value>, onClick/onChange
 * handlers, and a `class Component extends DCLogic` script with renderVals() and setState().
 * It is documentation tooling only and is never loaded by the application.
 */
(function () {
    class DCLogic {
        constructor(props) {
            this.props = props || {};
            this.state = {};
        }

        setState(patch) {
            this.state = Object.assign({}, this.state, typeof patch === 'function' ? patch(this.state) : patch);
            this.__render && this.__render();
        }

        forceUpdate() {
            this.__render && this.__render();
        }
    }

    window.DCLogic = DCLogic;

    const WHOLE = /^\s*\{\{\s*([^}]+?)\s*\}\}\s*$/;
    const HOLE = /\{\{\s*([^}]+?)\s*\}\}/g;
    const PROPS = ['checked', 'disabled', 'readOnly', 'value', 'selected'];

    function lookup(scope, path) {
        if (path === 'true') return true;
        if (path === 'false') return false;
        if (path === 'null') return null;
        if (/^-?\d+(\.\d+)?$/.test(path)) return Number(path);
        return path.split('.').reduce((value, key) => (value == null ? undefined : value[key]), scope);
    }

    function text(value) {
        return value == null ? '' : String(value);
    }

    function renderNode(node, scope, out) {
        if (node.nodeType === Node.TEXT_NODE) {
            out.appendChild(document.createTextNode(node.textContent.replace(HOLE, (_, p) => text(lookup(scope, p)))));
            return;
        }
        if (node.nodeType !== Node.ELEMENT_NODE) return;

        const tag = node.tagName.toLowerCase();
        if (tag === 'sc-for') {
            const list = lookup(scope, (node.getAttribute('list').match(WHOLE) || [])[1] || '') || [];
            const as = node.getAttribute('as') || 'item';
            list.forEach((item, index) => {
                const inner = Object.assign(Object.create(scope), { [as]: item, $index: index });
                node.childNodes.forEach((child) => renderNode(child, inner, out));
            });
            return;
        }
        if (tag === 'sc-if') {
            if (lookup(scope, (node.getAttribute('value').match(WHOLE) || [])[1] || '')) {
                node.childNodes.forEach((child) => renderNode(child, scope, out));
            }
            return;
        }
        if (tag === 'helmet') {
            node.childNodes.forEach((child) => renderNode(child, scope, out));
            return;
        }

        // Keep the namespace so <svg>, <polyline> and friends render as SVG, not as unknown HTML.
        const el = node.namespaceURI && node.namespaceURI !== 'http://www.w3.org/1999/xhtml'
            ? document.createElementNS(node.namespaceURI, node.tagName)
            : document.createElement(tag);
        for (const attr of Array.from(node.attributes)) {
            if (attr.name.startsWith('hint-')) continue;
            const whole = attr.value.match(WHOLE);
            const name = attr.name;
            if (whole) {
                const value = lookup(scope, whole[1]);
                if (name.startsWith('on')) {
                    if (typeof value === 'function') el.addEventListener(name.slice(2).toLowerCase(), value);
                    continue;
                }
                const prop = PROPS.find((p) => p.toLowerCase() === name);
                if (prop) {
                    el[prop] = prop === 'value' ? text(value) : Boolean(value);
                    if (prop !== 'value' && value) el.setAttribute(name, '');
                    continue;
                }
                if (value != null && value !== false) el.setAttribute(name, text(value));
                continue;
            }
            el.setAttribute(name, attr.value.replace(HOLE, (_, p) => text(lookup(scope, p))));
        }
        node.childNodes.forEach((child) => renderNode(child, scope, el));
        out.appendChild(el);
    }

    function mount() {
        const root = document.querySelector('x-dc');
        const script = document.querySelector('script[type="text/x-dc"]');
        if (!root) return;

        const template = root.cloneNode(true);
        let props = {};
        try {
            props = JSON.parse(script?.getAttribute('data-props') || '{}');
        } catch {
            props = {};
        }

        const Component = script ? new Function('DCLogic', `${script.textContent}\nreturn Component;`)(DCLogic) : DCLogic;
        const instance = new Component({});
        Object.keys(props).forEach((key) => {
            if (!key.startsWith('$') && props[key] && 'default' in props[key] && !(key in instance.props)) {
                instance.props[key] = props[key].default;
            }
        });

        instance.__render = () => {
            const vals = typeof instance.renderVals === 'function' ? instance.renderVals() : {};
            const fragment = document.createDocumentFragment();
            template.childNodes.forEach((child) => renderNode(child, vals, fragment));
            root.replaceChildren(fragment);
        };
        if (typeof instance.componentDidMount === 'function') instance.componentDidMount();
        instance.__render();
        document.documentElement.setAttribute('data-rendered', 'true');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', mount);
    } else {
        mount();
    }
})();
