const SVG_NS = 'http://www.w3.org/2000/svg';

/**
 * Builds a radar chart element. It does not read or change the page.
 *
 * @param {{
 *   axes: Array<{id: string, label: string, color: string}>,
 *   series: Array<{values: Record<string, number|null>, style: 'solid'|'dashed'}>,
 *   size: number,
 * }} spec
 *   Axes in display order and one or two series.
 * @returns {SVGElement}
 *   A standalone SVG element.
 */
export function renderRadar(spec) {
  const { axes, series, size } = spec;
  const svg = document.createElementNS(SVG_NS, 'svg');
  svg.setAttribute('class', 'lifebalance-radar');
  svg.setAttribute('viewBox', `0 0 ${size} ${size}`);
  svg.setAttribute('role', 'group');
  svg.setAttribute('aria-labelledby', 'lifebalance-radar-title');

  const title = element('title');
  title.id = 'lifebalance-radar-title';
  title.textContent = 'Колесо балансу';
  const desc = element('desc');
  desc.textContent = 'Оцінки секторів від 0 до 10. Неоцінений сектор не зафарбовано. Назви сфер підписані по контуру. Поруч є таблиця значень.';
  svg.append(title, desc);

  const center = size / 2;
  const radius = size / 2 - 30;
  const scoreSeries = series.find((item) => item.style === 'solid') ?? series[0];
  axes.forEach((axis, index) => {
    const angle = angleAt(index, axes.length);
    const ratio = hasValue(scoreSeries, axis.id) ? clamp(scoreSeries.values[axis.id]) / 10 : 0;
    appendSector(svg, center, radius, angle, axes.length, ratio, safeColor(axis.color), axis);
  });
  const guides = element('g');
  guides.setAttribute('class', 'lifebalance-radar__guides');
  for (let step = 1; step <= 5; step += 1) {
    const ring = element('circle');
    ring.setAttribute('cx', String(center));
    ring.setAttribute('cy', String(center));
    ring.setAttribute('r', (radius * step / 5).toFixed(2));
    ring.setAttribute('fill', 'none');
    ring.setAttribute('stroke', '#c5ccd4');
    ring.setAttribute('stroke-width', '1');
    ring.setAttribute('vector-effect', 'non-scaling-stroke');
    guides.append(ring);
  }

  axes.forEach((_axis, index) => {
    const angle = angleAt(index, axes.length);
    const line = element('line');
    line.setAttribute('x1', String(center));
    line.setAttribute('y1', String(center));
    line.setAttribute('x2', point(center, radius, angle, 1).x);
    line.setAttribute('y2', point(center, radius, angle, 1).y);
    line.setAttribute('stroke', '#c5ccd4');
    line.setAttribute('stroke-width', '1');
    line.setAttribute('vector-effect', 'non-scaling-stroke');
    guides.append(line);
  });

  for (const item of series) {
    const vertices = [];
    let any = false;
    axes.forEach((axis, index) => {
      const present = hasValue(item, axis.id);
      if (present) {
        any = true;
      }
      const ratio = present ? clamp(item.values[axis.id]) / 10 : 0;
      const spot = point(center, radius, angleAt(index, axes.length), ratio);
      vertices.push(`${spot.x},${spot.y}`);
    });
    if (!any) {
      continue;
    }
    if (item.style === 'dashed') {
      const shape = element('polygon');
      shape.setAttribute('points', vertices.join(' '));
      shape.setAttribute('fill', 'none');
      shape.setAttribute('stroke', '#c5ccd4');
      shape.setAttribute('stroke-dasharray', '2 3');
      shape.setAttribute('stroke-width', '1');
      shape.setAttribute('vector-effect', 'non-scaling-stroke');
      guides.append(shape);
      axes.forEach((axis, index) => {
        if (!hasValue(item, axis.id)) {
          return;
        }
        const spot = point(center, radius, angleAt(index, axes.length), clamp(item.values[axis.id]) / 10);
        const marker = element('circle');
        marker.setAttribute('cx', spot.x);
        marker.setAttribute('cy', spot.y);
        marker.setAttribute('r', '2.5');
        marker.setAttribute('fill', '#1F4E79');
        guides.append(marker);
      });
    }
  }
  svg.append(guides);
  appendLabels(svg, center, axes);
  return svg;
}

/**
 * Draws each sector name along its outer arc.
 *
 * @param {SVGElement} svg
 * @param {number} center
 * @param {Array<{label: string}>} axes
 */
function appendLabels(svg, center, axes) {
  const defs = element('defs');
  const labels = element('g');
  labels.setAttribute('class', 'lifebalance-radar__labels');
  const serial = chartSerial;
  chartSerial += 1;
  axes.forEach((axis, index) => {
    const angle = angleAt(index, axes.length);
    const span = axes.length === 0 ? 0 : (2 * Math.PI) / axes.length;
    const lower = Math.sin(angle + (span / 2)) > 0;
    const radius = 80;
    const from = lower ? angle + span : angle;
    const to = lower ? angle : angle + span;
    const sweep = lower ? 0 : 1;
    const start = point(center, radius, from, 1);
    const end = point(center, radius, to, 1);
    const id = `lb-radar-label-${serial}-${index}`;
    const arc = element('path');
    arc.setAttribute('id', id);
    arc.setAttribute('d', `M ${start.x} ${start.y} A ${radius.toFixed(2)} ${radius.toFixed(2)} 0 0 ${sweep} ${end.x} ${end.y}`);
    defs.append(arc);
    const text = element('text');
    text.setAttribute('class', 'lifebalance-radar__label');
    text.setAttribute('fill', '#243040');
    text.setAttribute('font-size', '4.2');
    text.setAttribute('text-anchor', 'middle');
    text.setAttribute('dominant-baseline', 'central');
    const pathText = element('textPath');
    pathText.setAttribute('href', `#${id}`);
    pathText.setAttribute('startOffset', '50%');
    pathText.textContent = axis.label;
    text.append(pathText);
    labels.append(text);
  });
  svg.append(labels);
  svg.insertBefore(defs, svg.firstChild);
}

/**
 * Keeps label paths unique when more than one chart is on the page.
 */
let chartSerial = 0;

/**
 * @param {string} name
 * @returns {SVGElement}
 */
function element(name) {
  return document.createElementNS(SVG_NS, name);
}

/**
 * Full slice that grows on hover and links to the matching form section.
 *
 * @param {SVGElement} svg
 * @param {number} center
 * @param {number} radius
 * @param {number} angle
 * @param {number} count
 * @param {number} ratio
 * @param {string} color
 * @param {{id: string, label: string}} axis
 */
function appendSector(svg, center, radius, angle, count, ratio, color, axis) {
  const id = /^[a-z0-9_]+$/.test(axis.id) ? axis.id : '';
  const hit = wedgePath(center, radius, angle, count, 1);
  const score = wedgePath(center, radius, angle, count, ratio);
  if (id === '') {
    if (score === '') {
      return;
    }
    const plain = element('path');
    plain.setAttribute('d', score);
    plain.setAttribute('fill', color);
    plain.setAttribute('fill-opacity', '1');
    plain.setAttribute('stroke', 'none');
    svg.append(plain);
    return;
  }
  const link = element('a');
  link.setAttribute('class', 'lifebalance-radar__sector');
  link.setAttribute('href', `#lifebalance-sector-${id}`);
  link.setAttribute('aria-label', axis.label);
  const target = element('path');
  target.setAttribute('class', 'lifebalance-radar__hit');
  target.setAttribute('d', hit);
  target.setAttribute('fill', color);
  target.setAttribute('fill-opacity', '0');
  target.setAttribute('stroke', 'none');
  link.append(target);
  const grow = element('g');
  grow.setAttribute('class', 'lifebalance-radar__grow');
  const echo = element('path');
  echo.setAttribute('class', 'lifebalance-radar__echo');
  echo.setAttribute('d', hit);
  echo.setAttribute('fill', color);
  echo.setAttribute('fill-opacity', '0');
  echo.setAttribute('stroke', 'none');
  grow.append(echo);
  if (score !== '') {
    const shape = element('path');
    shape.setAttribute('class', 'lifebalance-radar__score');
    shape.setAttribute('d', score);
    shape.setAttribute('fill', color);
    shape.setAttribute('fill-opacity', '1');
    shape.setAttribute('stroke', 'none');
    grow.append(shape);
  }
  link.append(grow);
  svg.append(link);
}

/**
 * @param {number} center
 * @param {number} radius
 * @param {number} angle
 * @param {number} count
 * @param {number} ratio
 * @returns {string}
 */
function wedgePath(center, radius, angle, count, ratio) {
  if (ratio <= 0 || count < 1) {
    return '';
  }
  const span = (radius * ratio).toFixed(2);
  const start = point(center, radius, angle, ratio);
  const end = point(center, radius, angle + (2 * Math.PI / count), ratio);
  return `M ${center.toFixed(2)} ${center.toFixed(2)} L ${start.x} ${start.y} A ${span} ${span} 0 0 1 ${end.x} ${end.y} Z`;
}

/**
 * @param {number} index
 * @param {number} count
 * @returns {number}
 */
function angleAt(index, count) {
  return (-Math.PI / 2) + (count === 0 ? 0 : index * 2 * Math.PI / count);
}

/**
 * @param {number} center
 * @param {number} radius
 * @param {number} angle
 * @param {number} ratio
 * @returns {{x: string, y: string}}
 */
function point(center, radius, angle, ratio) {
  return {
    x: (center + Math.cos(angle) * radius * ratio).toFixed(2),
    y: (center + Math.sin(angle) * radius * ratio).toFixed(2),
  };
}

/**
 * @param {{values?: Record<string, number|null>}|undefined} series
 * @param {string} id
 * @returns {boolean}
 */
function hasValue(series, id) {
  const value = series?.values?.[id];
  return typeof value === 'number' && Number.isFinite(value);
}

/**
 * @param {number} value
 * @returns {number}
 */
function clamp(value) {
  return Math.min(10, Math.max(0, value));
}

/**
 * @param {string} color
 * @returns {string}
 */
function safeColor(color) {
  return /^#[0-9A-Fa-f]{6}$/.test(color) ? color : '#6b7280';
}
