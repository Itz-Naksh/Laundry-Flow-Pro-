<?php
declare(strict_types=1);

namespace App\Helpers;

/**
 * Minimal PDF generator — A4, Helvetica/Helvetica-Bold, tables, auto page-break.
 * No external dependencies; uses raw PDF 1.4 format.
 */
class SimplePdf
{
    private float $W       = 595.28;   // A4 width  (points)
    private float $H       = 841.89;   // A4 height (points)
    private float $lMargin = 50.0;
    private float $rMargin = 50.0;
    private float $lineH   = 16.0;

    /** Completed page content streams */
    private array  $pages  = [];
    /** Current page content buffer */
    private string $buf    = '';
    /** Current Y coordinate (PDF bottom-relative) */
    private float  $y      = 0.0;

    public function __construct()
    {
        $this->startPage();
    }

    // ── Page management ───────────────────────────────────────────────────────

    private function startPage(): void
    {
        $this->buf = '';
        $this->y   = $this->H - 50.0;   // start 50 pt from top
    }

    public function addPage(): void
    {
        $this->pages[] = $this->buf;
        $this->startPage();
    }

    public function getY(): float  { return $this->y; }
    public function setY(float $y): void { $this->y = $y; }
    public function moveDown(float $amount = 0): void
    {
        $this->y -= ($amount > 0 ? $amount : $this->lineH);
    }

    public function contentWidth(): float
    {
        return $this->W - $this->lMargin - $this->rMargin;
    }

    // ── Drawing primitives ────────────────────────────────────────────────────

    /** Horizontal rule at current Y, then advance. */
    public function hRule(float $lw = 0.5): void
    {
        $x1 = $this->lMargin;
        $x2 = $this->W - $this->rMargin;
        $this->buf .= "{$lw} w {$x1} {$this->y} m {$x2} {$this->y} l S\n";
        $this->y   -= 6.0;
    }

    /** Filled rectangle. x, y = bottom-left corner (PDF coords). */
    public function fillRect(float $x, float $y, float $w, float $h, float $r, float $g, float $b): void
    {
        $this->buf .= "{$r} {$g} {$b} rg {$x} {$y} {$w} {$h} re f 0 0 0 rg\n";
    }

    /** Stroked rectangle. */
    public function strokeRect(float $x, float $y, float $w, float $h, float $lw = 0.5): void
    {
        $this->buf .= "{$lw} w {$x} {$y} {$w} {$h} re S\n";
    }

    /**
     * Place text at absolute position.
     * @param float $x  left edge (PDF coords)
     * @param float $y  baseline (PDF coords, bottom-relative)
     */
    public function text(float $x, float $y, string $text, float $size = 10, bool $bold = false): void
    {
        $font = $bold ? '/F2' : '/F1';
        $esc  = $this->esc($text);
        $this->buf .= "BT {$font} {$size} Tf {$x} {$y} Td ({$esc}) Tj ET\n";
    }

    // ── High-level write helpers ───────────────────────────────────────────────

    /** Write a line of text at current Y then advance down. */
    public function writeLine(
        string $text,
        float  $size     = 10,
        bool   $bold     = false,
        float  $xOffset  = 0,
        bool   $checkBreak = true
    ): void {
        if ($checkBreak && $this->y < 70) {
            $this->addPage();
        }
        $this->text($this->lMargin + $xOffset, $this->y, $text, $size, $bold);
        $this->y -= $this->lineH;
    }

    /** Section heading: bold text, line below. */
    public function sectionHead(string $title, float $size = 11): void
    {
        if ($this->y < 100) $this->addPage();
        $this->y -= 6.0;
        $this->writeLine($title, $size, true);
        $this->hRule(0.3);
    }

    // ── Table renderer ────────────────────────────────────────────────────────

    /**
     * Render a simple table.
     *
     * @param string[] $headers
     * @param array[]  $rows        Each row is an array of cell values (string/numeric)
     * @param float[]  $colWidths   Must sum to contentWidth()
     * @param float    $rowH        Height of each row in points
     */
    public function table(array $headers, array $rows, array $colWidths, float $rowH = 20): void
    {
        if ($this->y < 100) $this->addPage();
        $x0       = $this->lMargin;
        $baseline = 6.0; // text baseline offset from bottom of cell

        // ── Header row (dark blue fill, white text) ───────────────────────────
        $totalW = array_sum($colWidths);
        $this->fillRect($x0, $this->y - $rowH, $totalW, $rowH, 0.10, 0.31, 0.71);

        $cx = $x0 + 4;
        foreach ($headers as $i => $h) {
            // White text for header
            $esc = $this->esc((string) $h);
            $this->buf .= "BT /F2 9 Tf 1 1 1 rg {$cx} " . ($this->y - $rowH + $baseline) . " Td ({$esc}) Tj ET 0 0 0 rg\n";
            $cx += $colWidths[$i];
        }
        // Vertical dividers in header
        $cx = $x0;
        foreach ($colWidths as $cw) {
            $cx += $cw;
            if ($cx < $x0 + $totalW) {
                $yb = $this->y - $rowH;
                $yt = $this->y;
                $this->buf .= "0.3 w 1 1 1 RG {$cx} {$yb} m {$cx} {$yt} l S 0 0 0 RG\n";
            }
        }
        $this->y -= $rowH;

        // ── Data rows ─────────────────────────────────────────────────────────
        foreach ($rows as $ri => $row) {
            if ($this->y < 70) {
                $this->addPage();
                // re-draw header on new page
                $this->table($headers, [], $colWidths, $rowH); // header only trick
            }

            // Alternate fill
            if ($ri % 2 === 0) {
                $this->fillRect($x0, $this->y - $rowH, $totalW, $rowH, 0.96, 0.97, 0.99);
            }

            $cx = $x0 + 4;
            foreach ($row as $i => $cell) {
                $this->text($cx, $this->y - $rowH + $baseline, (string) $cell, 9);
                $cx += ($colWidths[$i] ?? 0);
            }

            // Bottom border of row
            $this->buf .= "0.3 w 0.85 0.87 0.90 RG {$x0} " . ($this->y - $rowH) . " m " . ($x0 + $totalW) . " " . ($this->y - $rowH) . " l S 0 0 0 RG\n";

            $this->y -= $rowH;
        }

        // Bottom border of whole table
        $this->buf .= "0.5 w {$x0} {$this->y} m " . ($x0 + $totalW) . " {$this->y} l S\n";
        $this->y -= 8.0;
    }

    // ── PDF binary assembly ───────────────────────────────────────────────────

    public function render(): string
    {
        // Finalize last page
        $streams   = $this->pages;
        $streams[] = $this->buf;
        $n         = count($streams);

        // Object ID plan:
        // 1          → Catalog
        // 2          → Pages
        // 3 … n+2    → Page objects
        // n+3 … 2n+2 → Content streams
        // 2n+3       → Font F1 (Helvetica)
        // 2n+4       → Font F2 (Helvetica-Bold)
        $catalogId = 1;
        $pagesId   = 2;
        $pageIds   = range(3, $n + 2);
        $strmIds   = range($n + 3, 2 * $n + 2);
        $f1Id      = 2 * $n + 3;
        $f2Id      = 2 * $n + 4;
        $totalObjs = $f2Id + 1; // 0-indexed; obj 0 is the free entry

        $pdf     = "%PDF-1.4\n%\xe2\xe3\xcf\xd3\n";
        $offsets = [];

        $writeObj = function (int $id, string $dict, string $stream = '') use (&$pdf, &$offsets): void {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n";
            if ($stream !== '') {
                $len  = strlen($stream);
                $pdf .= "<< /Length {$len} >>\nstream\n{$stream}\nendstream\n";
            } else {
                $pdf .= "{$dict}\n";
            }
            $pdf .= "endobj\n";
        };

        // 1: Catalog
        $writeObj($catalogId, "<< /Type /Catalog /Pages {$pagesId} 0 R >>");

        // 2: Pages
        $kids = implode(' 0 R ', $pageIds) . ' 0 R';
        $writeObj($pagesId, "<< /Type /Pages /Kids [{$kids}] /Count {$n} >>");

        // Font resource dict (shared by all pages)
        $res = "<< /Font << /F1 {$f1Id} 0 R /F2 {$f2Id} 0 R >> >>";

        // 3…n+2: Page objects
        foreach ($pageIds as $i => $pgId) {
            $stId = $strmIds[$i];
            $writeObj($pgId,
                "<< /Type /Page /Parent {$pagesId} 0 R " .
                "/MediaBox [0 0 {$this->W} {$this->H}] " .
                "/Resources {$res} " .
                "/Contents {$stId} 0 R >>"
            );
        }

        // n+3…2n+2: Content streams
        foreach ($strmIds as $i => $stId) {
            $writeObj($stId, '', $streams[$i]);
        }

        // Fonts (built-in Type1, no embedding needed)
        $writeObj($f1Id, "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>");
        $writeObj($f2Id, "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>");

        // Cross-reference table
        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 {$totalObjs}\n";
        $pdf .= "0000000000 65535 f \n";
        for ($id = 1; $id < $totalObjs; $id++) {
            $pdf .= str_pad((string) ($offsets[$id] ?? 0), 10, '0', STR_PAD_LEFT) . " 00000 n \n";
        }

        $pdf .= "trailer\n<< /Size {$totalObjs} /Root {$catalogId} 0 R >>\n";
        $pdf .= "startxref\n{$xrefOffset}\n%%EOF";

        return $pdf;
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    private function esc(string $s): string
    {
        // Strip non-printable-ASCII then escape PDF string delimiters
        $s = preg_replace('/[^\x20-\x7E]/', '', $s) ?? '';
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
    }
}
