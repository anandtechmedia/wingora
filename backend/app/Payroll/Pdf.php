<?php

namespace App\Payroll;

/**
 * Minimal single-purpose PDF writer (PDF 1.4, standard Helvetica fonts, WinAnsi text, lines and rectangles).
 * Enough for payslips without adding a rendering dependency. Text is escaped; no external resources are loaded.
 */
final class Pdf
{
    private const W = 612;

    private const H = 792;

    /** @var list<string> */
    private array $pages = [];

    private string $ops = '';

    public function __construct(private readonly string $title)
    {
        $this->addPage();
    }

    public function addPage(): void
    {
        if ($this->ops !== '') {
            $this->pages[] = $this->ops;
        }
        $this->ops = '';
    }

    /** Coordinates are from the top-left, in points. */
    public function text(float $x, float $y, string $text, float $size = 10, bool $bold = false, string $align = 'left', ?float $width = null): void
    {
        $font = $bold ? 'F2' : 'F1';
        $encoded = $this->encode($text);
        if ($align !== 'left' && $width !== null) {
            $w = $this->width($encoded, $size, $bold);
            $x += $align === 'right' ? $width - $w : ($width - $w) / 2;
        }
        $this->ops .= sprintf("BT /%s %.1F Tf %.2F %.2F Td (%s) Tj ET\n", $font, $size, $x, self::H - $y, $this->escape($encoded));
    }

    public function line(float $x1, float $y1, float $x2, float $y2, float $width = 0.5, float $gray = 0.75): void
    {
        $this->ops .= sprintf("%.2F G %.2F w %.2F %.2F m %.2F %.2F l S 0 G\n", $gray, $width, $x1, self::H - $y1, $x2, self::H - $y2);
    }

    public function rect(float $x, float $y, float $w, float $h, float $gray = 0.95): void
    {
        $this->ops .= sprintf("%.2F g %.2F %.2F %.2F %.2F re f 0 g\n", $gray, $x, self::H - $y - $h, $w, $h);
    }

    public function output(): string
    {
        $pages = [...$this->pages, $this->ops];
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = [];
        $next = 5;
        $pageObjects = [];
        foreach ($pages as $content) {
            $pageObjects[] = [$next, $next + 1, $content];
            $kids[] = "{$next} 0 R";
            $next += 2;
        }
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($pages).' >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        foreach ($pageObjects as [$page, $stream, $content]) {
            $objects[$page] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %d %d] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>', self::W, self::H, $stream);
            $objects[$stream] = '<< /Length '.strlen($content)." >>\nstream\n{$content}endstream";
        }
        $info = $next;
        $objects[$info] = '<< /Title ('.$this->escape($this->encode($this->title)).') /Producer (Wingora) >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $n => $body) {
            $offsets[$n] = strlen($pdf);
            $pdf .= "{$n} 0 obj\n{$body}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref
0 '.(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer
<< /Size '.(count($objects) + 1)." /Root 1 0 R /Info {$info} 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }

    private function encode(string $text): string
    {
        $text = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $text) ?? '';
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);

        return $converted === false ? preg_replace('/[^\x20-\x7E]/', '?', $text) : $converted;
    }

    private function escape(string $s): string
    {
        return strtr($s, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
    }

    /** Approximate Helvetica advance widths, good enough for right-aligning figures. */
    private function width(string $s, float $size, bool $bold): float
    {
        $units = 0;
        foreach (str_split($s) as $c) {
            $units += match (true) {
                ctype_digit($c) => 556,
                $c === ' ' || $c === '.' || $c === ',' => 278,
                $c === '-' => 333,
                ctype_upper($c) => $bold ? 722 : 667,
                default => $bold ? 556 : 500,
            };
        }

        return $units * $size / 1000;
    }
}
