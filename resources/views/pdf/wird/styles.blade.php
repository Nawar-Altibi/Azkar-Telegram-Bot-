/* Palette from the bot avatar: night navy, teal dusk, lantern gold and warm cream. */
@page { background-color: #f8f4ec; }
body { font-family: amiri; direction: rtl; color: #1f2a3d; font-size: 12pt; }

.page-header { font-family: tajawal; text-align: center; font-size: 8pt; color: #b08a4a; }
.page-footer { font-family: tajawal; text-align: center; font-size: 8.5pt; color: #b08a4a; }

.cover { background-color: #1d2f5e; background: linear-gradient(135deg, #6f7a6a 0%, #2f6f7e 22%, #23477a 60%, #172554 100%); border-radius: 4mm; padding: 6mm 6mm 5mm 6mm; text-align: center; margin-bottom: 5mm; }
.ornament { font-family: amiri; }
.cover .ornament { color: #f2c46d; font-size: 13pt; }
.cover h1 { font-family: tajawal; font-weight: bold; font-size: 22pt; color: #fff6e4; margin: 1mm 0 0 0; line-height: 1.3; }
.cover .subtitle { font-family: tajawal; font-size: 10pt; color: #f2cf8f; margin-top: 1.5mm; }

.entry { margin-bottom: 4mm; }
.item { background-color: #ffffff; border: 0.25mm solid #ebe2cf; border-radius: 3mm; padding: 2mm 4mm 3mm 4mm; }
.head { font-family: tajawal; line-height: 2.3; padding-right: 1mm; }
.num { font-weight: bold; font-size: 9.5pt; color: #fff6e4; background-color: #1d3566; }
.title { font-weight: bold; font-size: 11.5pt; color: #1d3566; }
.reps { font-weight: bold; font-size: 11pt; color: #1d2a4a; background-color: #f0b85a; }
.reps-once { font-size: 8.5pt; color: #8b8676; }
.reps-note { font-family: tajawal; font-weight: bold; font-size: 9.5pt; color: #94601c; background-color: #fbecd2; border-radius: 1.5mm; padding: 1.2mm 2.5mm; margin-top: 1mm; }
.text { font-size: 16pt; line-height: 1.9; text-align: justify; color: #1a2233; margin: 0.5mm 0 0 0; }
.para { margin-top: 2.5mm; }
/* Only for a card that would not fit on one page at the normal size. */
.compact-1 .text { font-size: 14.5pt; line-height: 1.8; }
.compact-2 .text { font-size: 13pt; line-height: 1.75; }
.virtue { background-color: #eef4f5; border-right: 0.8mm solid #2f7682; padding: 1.8mm 3mm; font-size: 11pt; line-height: 1.65; color: #24434d; margin-top: 2mm; }
.virtue b { color: #1d3566; }
.source { font-family: tajawal; font-size: 8pt; color: #8b8676; margin-top: 1.8mm; }

.closing { text-align: center; margin-top: 3mm; padding: 4mm 6mm; border-top: 0.25mm solid #e6d3ad; }
.closing .ornament { color: #d79b45; font-size: 14pt; }
.closing-title { font-family: tajawal; font-weight: bold; font-size: 13pt; color: #1d3566; margin-top: 1mm; }
.closing-blessing { font-size: 13pt; color: #8a6430; margin-top: 1mm; }
@if ($print ?? false)

/*
 * Printable copy: white paper, no large ink fills, and every text colour dark enough to stay crisp
 * when a black-and-white printer turns it into grey. Accents are borders, so colour printers still show the palette.
 */
@page { background-color: #ffffff; }
body { color: #111827; }
.page-header, .page-footer { color: #5b4a2a; }
.cover { background-color: #ffffff; background: #ffffff; border: 0.6mm solid #1d3566; }
.cover .ornament { color: #8a6420; }
.cover h1 { color: #1d3566; }
.cover .subtitle { color: #5b4a2a; }
.item { border: 0.3mm solid #9c9178; }
.num { color: #ffffff; background-color: #1d3566; }
.title { color: #1d3566; }
.reps { color: #111827; background-color: #fbe7c2; }
.reps-once { color: #4b4a44; }
.reps-note { color: #5b3d10; background-color: #ffffff; border: 0.25mm solid #b08a4a; }
.text { color: #000000; }
.virtue { background-color: #ffffff; border-right: 0.8mm solid #1f5e68; color: #1f2937; }
.virtue b { color: #1d3566; }
.source { color: #4b4a44; }
.closing { border-top: 0.3mm solid #9c9178; }
.closing .ornament { color: #8a6420; }
.closing-title { color: #1d3566; }
.closing-blessing { color: #5b3d10; }
@endif
