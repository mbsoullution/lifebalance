const SVG_NS = 'http://www.w3.org/2000/svg';
const PAD_X = 28;
const PAD_Y = 16;

/**
 * Positions for a trend. X is proportional to time. Null values are skipped.
 *
 * @param {Array<{time: number, value: number|null, label?: string}>} points
 *   Chronological points.
 * @param {number} [width]
 *   Chart width.
 * @param {number} [height]
 *   Chart height.
 * @returns {{
 *   dots: Array<{x: string, y: string, label: string}>,
 *   line: string,
 * }}
 *   Dot coordinates and a polyline. The line is empty below two dots.
 */
export function layoutTrend(points, width = 320, height = 180) {
  const plot = points.filter((point) => typeof point.value === 'number' && Number.isFinite(point.value));
  const innerW = width - (PAD_X * 2);
  const innerH = height - (PAD_Y * 2);
  if (plot.length === 0) {
    return { dots: [], line: '' };
  }
  const times = plot.map((point) => point.time);
  const min = Math.min(...times);
  const max = Math.max(...times);
  const span = max - min;
  const dots = plot.map((point) => {
    const ratio = span === 0 ? 0.5 : (point.time - min) / span;
    return {
      x: (PAD_X + (ratio * innerW)).toFixed(2),
      y: (PAD_Y + ((1 - (point.value / 10)) * innerH)).toFixed(2),
      label: point.label ?? String(point.value),
    };
  });
  return {
    dots,
    line: dots.length >= 2 ? dots.map((dot) => `${dot.x},${dot.y}`).join(' ') : '',
  };
}

/**
 * Builds a trend chart. It does not read the page.
 *
 * @param {{
 *   points: Array<{time: number, value: number|null, label?: string}>,
 *   width?: number,
 *   height?: number,
 * }} spec
 *   Chronological points.
 * @returns {SVGElement}
 *   A standalone SVG element.
 */
export function renderTrend(spec) {
  const width = spec.width ?? 320;
  const height = spec.height ?? 180;
  const layout = layoutTrend(spec.points, width, height);
  const svg = element('svg');
  svg.setAttribute('class', 'lifebalance-trend-chart');
  svg.setAttribute('viewBox', `0 0 ${width} ${height}`);
  svg.setAttribute('role', 'img');
  svg.setAttribute('aria-labelledby', 'lifebalance-trend-title');

  const title = element('title');
  title.id = 'lifebalance-trend-title';
  title.textContent = 'Динаміка оцінок';
  const desc = element('desc');
  desc.textContent = 'Вісь часу пропорційна датам. Вісь оцінок від 0 до 10. Поруч є таблиця.';
  svg.append(title, desc);

  const innerH = height - (PAD_Y * 2);
  text(svg, '4', String(PAD_Y + 4), '10');
  text(svg, '4', String(PAD_Y + innerH), '0');
  if (layout.line) {
    const line = element('polyline');
    line.setAttribute('points', layout.line);
    line.setAttribute('fill', 'none');
    line.setAttribute('stroke', '#1F4E79');
    line.setAttribute('stroke-width', '1.5');
    svg.append(line);
  }
  for (const dot of layout.dots) {
    const circle = element('circle');
    circle.setAttribute('cx', dot.x);
    circle.setAttribute('cy', dot.y);
    circle.setAttribute('r', '3');
    circle.setAttribute('fill', '#1F4E79');
    const tip = element('title');
    tip.textContent = dot.label;
    circle.append(tip);
    svg.append(circle);
  }
  return svg;
}

/**
 * Replaces the server chart with the same geometry and tooltip titles.
 *
 * @param {ParentNode} root
 *   Element that carries data-points.
 */
export function mountTrend(root) {
  if (!(root instanceof Element)) {
    return;
  }
  const encoded = root.getAttribute('data-points');
  if (!encoded) {
    return;
  }
  const points = JSON.parse(encoded);
  if (!Array.isArray(points)) {
    return;
  }
  const svg = renderTrend({ points });
  const previous = root.querySelector('svg');
  if (previous) {
    previous.replaceWith(svg);
  }
  else {
    root.append(svg);
  }
}

/**
 * @param {string} name
 * @returns {SVGElement}
 */
function element(name) {
  return document.createElementNS(SVG_NS, name);
}

/**
 * @param {SVGElement} svg
 * @param {string} x
 * @param {string} y
 * @param {string} value
 */
function text(svg, x, y, value) {
  const node = element('text');
  node.setAttribute('x', x);
  node.setAttribute('y', y);
  node.textContent = value;
  svg.append(node);
}

if (typeof Drupal !== 'undefined' && typeof once !== 'undefined') {
  Drupal.behaviors.lifebalanceTrend = {
    attach(context) {
      once('lifebalance-trend', '[data-lifebalance-trend]', context).forEach((root) => {
        mountTrend(root);
      });
    },
  };
}
