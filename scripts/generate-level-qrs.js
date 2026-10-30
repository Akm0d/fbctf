#!/usr/bin/env node
'use strict';

const fs = require('fs');
const path = require('path');
const { BarcodeFormat, QRCodeWriter } = require('@zxing/library');

const outputDir = process.argv[2];
if (!outputDir) {
  throw new Error('Usage: generate-level-qrs.js OUTPUT_DIR < levels.tsv');
}
fs.mkdirSync(outputDir, { recursive: true, mode: 0o700 });

function escapeHtml(value) {
  return value.replace(/[&<>"']/g, (character) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  })[character]);
}

function qrSvg(answer) {
  const matrix = new QRCodeWriter().encode(
    answer,
    BarcodeFormat.QR_CODE,
    160,
    160,
    new Map(),
  );
  let modules = '';
  for (let y = 0; y < matrix.getHeight(); y += 1) {
    for (let x = 0; x < matrix.getWidth(); x += 1) {
      if (matrix.get(x, y)) {
        modules += `<rect x="${x}" y="${y}" width="1" height="1"/>`;
      }
    }
  }
  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${matrix.getWidth()} ${matrix.getHeight()}" shape-rendering="crispEdges"><rect width="100%" height="100%" fill="white"/><g fill="black">${modules}</g></svg>`;
}

const rows = fs.readFileSync(0, 'utf8').trim().split('\n').filter(Boolean).map((line) => {
  const [stage, country, placement, answer] = line.split('\t');
  return { stage, country, placement, answer: answer || '' };
});

const cards = [];
for (const row of rows) {
  const base = `stage-${row.stage}-${row.country.toLowerCase().replace(/[^a-z0-9]+/g, '-')}`;
  let visual = '<div class="pending">ANSWER PENDING</div>';
  if (row.answer) {
    const svg = qrSvg(row.answer);
    fs.writeFileSync(path.join(outputDir, `${base}.svg`), svg, { mode: 0o600 });
    visual = `<img src="${base}.svg" alt="Stage ${escapeHtml(row.stage)} QR code">`;
  }
  cards.push(`<section><h1>Stage ${escapeHtml(row.stage)} — ${escapeHtml(row.country)}</h1><p>${escapeHtml(row.placement)}</p>${visual}</section>`);
}

const sheet = `<!doctype html><html><head><meta charset="utf-8"><title>The House Is Keeping Secrets — Organizer QR Sheet</title><style>@page{size:letter;margin:.4in}body{font-family:sans-serif}section{box-sizing:border-box;border:2px solid #222;display:inline-block;margin:1%;padding:16px;text-align:center;vertical-align:top;width:47%;page-break-inside:avoid}h1{font-size:18px}p{min-height:2.5em}img{height:3.2in;max-width:100%}.pending{border:3px dashed #900;color:#900;font-size:24px;font-weight:bold;margin:1in 0;padding:.5in}</style></head><body>${cards.join('')}</body></html>`;
fs.writeFileSync(path.join(outputDir, 'printable-sheet.html'), sheet, { mode: 0o600 });
console.log(`Generated ${rows.filter((row) => row.answer).length} QR codes and one printable sheet.`);
