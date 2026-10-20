import assert from 'node:assert/strict';
import { test } from 'node:test';
import { layoutTrend } from './trend.js';

test('trend x follows time and y maps 0 to 10', () => {
  const chart = layoutTrend([
    { time: 100, value: 0, label: 'low' },
    { time: 200, value: null },
    { time: 300, value: 10, label: 'high' },
  ]);
  assert.equal(chart.dots.length, 2);
  assert.equal(chart.dots[0].x, '28.00');
  assert.equal(chart.dots[0].y, '164.00');
  assert.equal(chart.dots[1].x, '292.00');
  assert.equal(chart.dots[1].y, '16.00');
  assert.equal(chart.dots[0].label, 'low');
  assert.equal(chart.line, '28.00,164.00 292.00,16.00');
});

test('equal timestamps share the middle of the plot', () => {
  const chart = layoutTrend([
    { time: 50, value: 5 },
    { time: 50, value: 5 },
  ]);
  assert.equal(chart.dots[0].x, '160.00');
  assert.equal(chart.dots[1].x, '160.00');
  assert.equal(chart.dots[0].y, '90.00');
});
