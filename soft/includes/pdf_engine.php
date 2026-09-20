<?php
/**
 * Go4Fin PDF Engine
 * Pure PHP A4 PDF Generator without external dependencies.
 * Produces valid, standard-compliant PDF 1.4 documents.
 */

class Go4FinPDF {
    protected $page = 0;
    protected $n = 2; // object counter
    protected $offsets = [];
    protected $buffer = '';
    protected $pages = [];
    protected $state = 0;
    protected $fonts = [];
    protected $currentFont = 'helvetica';
    protected $currentStyle = '';
    protected $fontSizePt = 10;
    protected $fontSize = 3.527; // in mm (10 * 25.4 / 72)
    protected $x = 15;
    protected $y = 15;
    protected $lMargin = 15;
    protected $rMargin = 15;
    protected $tMargin = 15;
    protected $bMargin = 15;
    protected $w = 210; // A4 width in mm
    protected $h = 297; // A4 height in mm
    protected $k = 2.83464567; // scale factor (pt per mm)
    protected $lineWidth = 0.2;
    protected $drawColor = '0 0 0 RG';
    protected $fillColor = '1 1 1 rg';
    protected $textColor = '0 0 0 rg';
    protected $autoPageBreak = true;
    protected $bMarginLimit = 20;

    // Header & Footer Callbacks
    public $headerCallback = null;
    public $footerCallback = null;
    public $docTitle = 'GO4FIN Document';

    public function __construct() {
        // Standard Core Type 1 Fonts in PDF
        $this->fonts = [
            'helvetica' => ['name' => 'Helvetica'],
            'helvetica-b' => ['name' => 'Helvetica-Bold'],
            'helvetica-i' => ['name' => 'Helvetica-Oblique'],
            'helvetica-bi' => ['name' => 'Helvetica-BoldOblique'],
            'times' => ['name' => 'Times-Roman'],
            'times-b' => ['name' => 'Times-Bold'],
            'courier' => ['name' => 'Courier'],
            'courier-b' => ['name' => 'Courier-Bold']
        ];
    }

    public function SetMargins($left, $top, $right = null) {
        $this->lMargin = $left;
        $this->tMargin = $top;
        $this->rMargin = ($right !== null) ? $right : $left;
    }

    public function AddPage() {
        $this->page++;
        $this->pages[$this->page] = '';
        $this->x = $this->lMargin;
        $this->y = $this->tMargin;

        // Trigger Header callback if defined
        if (is_callable($this->headerCallback)) {
            call_user_func($this->headerCallback, $this);
        }
    }

    public function PageNo() {
        return $this->page;
    }

    public function SetFont($family, $style = '', $size = 10) {
        $family = strtolower(trim($family));
        if ($family === 'arial' || $family === 'sans-serif') $family = 'helvetica';
        if ($family === 'serif') $family = 'times';
        if ($family === 'monospace') $family = 'courier';

        $style = strtoupper(trim($style));
        $styleKey = '';
        if (strpos($style, 'B') !== false) $styleKey .= 'b';
        if (strpos($style, 'I') !== false) $styleKey .= 'i';

        $fontId = $family . ($styleKey ? '-' . $styleKey : '');
        if (!isset($this->fonts[$fontId])) {
            $fontId = 'helvetica';
        }

        $this->currentFont = $fontId;
        $this->currentStyle = $style;
        $this->fontSizePt = $size;
        $this->fontSize = $size / $this->k;

        if ($this->page > 0) {
            $fontIdx = $this->getFontNumber($this->currentFont);
            $this->out(sprintf('/F%d %.2f Tf', $fontIdx, $this->fontSizePt));
        }
    }

    protected function getFontNumber($fontId) {
        $keys = array_keys($this->fonts);
        $idx = array_search($fontId, $keys);
        return ($idx !== false) ? ($idx + 1) : 1;
    }

    public function SetTextColor($r, $g = null, $b = null) {
        if ($g === null && $b === null) {
            $c = $r / 255.0;
            $this->textColor = sprintf('%.3f g', $c);
        } else {
            $this->textColor = sprintf('%.3f %.3f %.3f rg', $r/255.0, $g/255.0, $b/255.0);
        }
        if ($this->page > 0) $this->out($this->textColor);
    }

    public function SetFillColor($r, $g = null, $b = null) {
        if ($g === null && $b === null) {
            $c = $r / 255.0;
            $this->fillColor = sprintf('%.3f g', $c);
        } else {
            $this->fillColor = sprintf('%.3f %.3f %.3f rg', $r/255.0, $g/255.0, $b/255.0);
        }
        if ($this->page > 0) $this->out($this->fillColor);
    }

    public function SetDrawColor($r, $g = null, $b = null) {
        if ($g === null && $b === null) {
            $c = $r / 255.0;
            $this->drawColor = sprintf('%.3f G', $c);
        } else {
            $this->drawColor = sprintf('%.3f %.3f %.3f RG', $r/255.0, $g/255.0, $b/255.0);
        }
        if ($this->page > 0) $this->out($this->drawColor);
    }

    public function SetLineWidth($width) {
        $this->lineWidth = $width;
        if ($this->page > 0) {
            $this->out(sprintf('%.2f w', $width * $this->k));
        }
    }

    public function Line($x1, $y1, $x2, $y2) {
        $this->out(sprintf('%.2f %.2f m %.2f %.2f l S', $x1 * $this->k, ($this->h - $y1) * $this->k, $x2 * $this->k, ($this->h - $y2) * $this->k));
    }

    public function Rect($x, $y, $w, $h, $style = 'D') {
        $op = 'S';
        if ($style === 'F') $op = 'f';
        elseif ($style === 'FD' || $style === 'DF' || $style === 'B') $op = 'B';

        $this->out(sprintf('%.2f %.2f %.2f %.2f re %s', $x * $this->k, ($this->h - ($y + $h)) * $this->k, $w * $this->k, $h * $this->k, $op));
    }

    public function RoundedRect($x, $y, $w, $h, $r = 3, $style = 'D') {
        // Fallback to Rect
        $this->Rect($x, $y, $w, $h, $style);
    }

    public function GetX() { return $this->x; }
    public function GetY() { return $this->y; }
    public function SetX($x) { $this->x = $x; }
    public function SetY($y) { $this->y = $y; }
    public function SetXY($x, $y) { $this->x = $x; $this->y = $y; }

    public function Ln($h = null) {
        $this->x = $this->lMargin;
        $this->y += ($h !== null) ? $h : ($this->fontSize * 1.3);
    }

    public function GetStringWidth($s) {
        // Approximate character width in mm (proportional estimation for Helvetica)
        $len = strlen($s);
        $w = 0;
        for ($i = 0; $i < $len; $i++) {
            $ch = $s[$i];
            if (in_array($ch, ['i', 'l', ' ', '.', ',', ';', ':', '!', '\''])) {
                $w += 0.28;
            } elseif (in_array($ch, ['m', 'w', 'M', 'W', 'Q', '@'])) {
                $w += 0.85;
            } elseif ($ch >= 'A' && $ch <= 'Z') {
                $w += 0.65;
            } else {
                $w += 0.52;
            }
        }
        return ($w * $this->fontSizePt) / $this->k;
    }

    public function Cell($w, $h = 0, $txt = '', $border = 0, $ln = 0, $align = 'L', $fill = false) {
        if ($this->autoPageBreak && ($this->y + $h > $this->h - $this->bMarginLimit)) {
            $this->AddPage();
        }

        $k = $this->k;
        $s = '';

        // Draw background fill
        if ($fill) {
            $s .= sprintf('%.2f %.2f %.2f %.2f re f ', $this->x * $k, ($this->h - ($this->y + $h)) * $k, $w * $k, $h * $k);
        }

        // Draw borders
        if ($border == 1 || $border === '1') {
            $s .= sprintf('%.2f %.2f %.2f %.2f re S ', $this->x * $k, ($this->h - ($this->y + $h)) * $k, $w * $k, $h * $k);
        } elseif (is_string($border)) {
            $x = $this->x * $k;
            $y = ($this->h - $this->y) * $k;
            $yb = ($this->h - ($this->y + $h)) * $k;
            $xr = ($this->x + $w) * $k;
            if (strpos($border, 'L') !== false) $s .= sprintf('%.2f %.2f m %.2f %.2f l S ', $x, $y, $x, $yb);
            if (strpos($border, 'T') !== false) $s .= sprintf('%.2f %.2f m %.2f %.2f l S ', $x, $y, $xr, $y);
            if (strpos($border, 'R') !== false) $s .= sprintf('%.2f %.2f m %.2f %.2f l S ', $xr, $y, $xr, $yb);
            if (strpos($border, 'B') !== false) $s .= sprintf('%.2f %.2f m %.2f %.2f l S ', $x, $yb, $xr, $yb);
        }

        // Render text
        if ($txt !== '') {
            $cleanTxt = $this->escapeText($txt);
            $fontIdx = $this->getFontNumber($this->currentFont);

            $strWidth = $this->GetStringWidth($txt);
            $dx = 1.5; // left padding
            if ($align === 'R') {
                $dx = max(1.5, $w - $strWidth - 1.5);
            } elseif ($align === 'C') {
                $dx = max(1.5, ($w - $strWidth) / 2);
            }

            // Vertical baseline center
            $textY = $this->y + ($h / 2) + ($this->fontSize / 2.8);

            $s .= sprintf('BT /F%d %.2f Tf %s %.2f %.2f Td (%s) Tj ET ',
                $fontIdx,
                $this->fontSizePt,
                $this->textColor,
                ($this->x + $dx) * $k,
                ($this->h - $textY) * $k,
                $cleanTxt
            );
        }

        if ($s) {
            $this->out($s);
        }

        if ($ln > 0) {
            $this->y += $h;
            $this->x = $this->lMargin;
        } else {
            $this->x += $w;
        }
    }

    public function MultiCell($w, $h, $txt, $border = 0, $align = 'L', $fill = false) {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $txt));
        $cw = $w - 3; // usable width inside cell

        foreach ($lines as $line) {
            $words = explode(' ', $line);
            $currentLine = '';

            foreach ($words as $word) {
                $testLine = $currentLine ? ($currentLine . ' ' . $word) : $word;
                if ($this->GetStringWidth($testLine) > $cw && $currentLine !== '') {
                    $this->Cell($w, $h, $currentLine, $border, 1, $align, $fill);
                    $currentLine = $word;
                } else {
                    $currentLine = $testLine;
                }
            }
            if ($currentLine !== '') {
                $this->Cell($w, $h, $currentLine, $border, 1, $align, $fill);
            }
        }
    }

    public function Table($headers, $rows, $widths = [], $aligns = [], $headerBg = [15, 23, 42], $headerText = [255, 255, 255]) {
        $colCount = count($headers);
        $totalWidth = $this->w - $this->lMargin - $this->rMargin;

        if (empty($widths)) {
            $defW = $totalWidth / $colCount;
            $widths = array_fill(0, $colCount, $defW);
        }

        // Header
        $this->SetFont('helvetica', 'B', 8.5);
        $this->SetFillColor($headerBg[0], $headerBg[1], $headerBg[2]);
        $this->SetTextColor($headerText[0], $headerText[1], $headerText[2]);
        $this->SetDrawColor(203, 213, 225);
        $this->SetLineWidth(0.2);

        foreach ($headers as $i => $hdr) {
            $al = isset($aligns[$i]) ? $aligns[$i] : 'L';
            $this->Cell($widths[$i], 7.5, $hdr, 1, 0, $al, true);
        }
        $this->Ln(7.5);

        // Rows
        $this->SetFont('helvetica', '', 8.5);
        $isOdd = false;
        foreach ($rows as $row) {
            if ($this->y + 7.5 > $this->h - $this->bMarginLimit) {
                $this->AddPage();
                // Re-print header
                $this->SetFont('helvetica', 'B', 8.5);
                $this->SetFillColor($headerBg[0], $headerBg[1], $headerBg[2]);
                $this->SetTextColor($headerText[0], $headerText[1], $headerText[2]);
                foreach ($headers as $i => $hdr) {
                    $al = isset($aligns[$i]) ? $aligns[$i] : 'L';
                    $this->Cell($widths[$i], 7.5, $hdr, 1, 0, $al, true);
                }
                $this->Ln(7.5);
                $this->SetFont('helvetica', '', 8.5);
            }

            if ($isOdd) {
                $this->SetFillColor(248, 250, 252);
            } else {
                $this->SetFillColor(255, 255, 255);
            }
            $this->SetTextColor(30, 41, 59);

            foreach ($row as $i => $val) {
                $al = isset($aligns[$i]) ? $aligns[$i] : 'L';
                $this->Cell($widths[$i], 7, (string)$val, 1, 0, $al, true);
            }
            $this->Ln(7);
            $isOdd = !$isOdd;
        }
    }

    protected function escapeText($s) {
        $s = str_replace(['₹', '–', '—', '•', '·'], ['Rs. ', '-', '-', '-', '-'], (string)$s);
        $conv = @iconv('UTF-8', 'windows-1252//IGNORE', $s);
        if ($conv !== false) {
            $s = $conv;
        } else {
            $s = mb_convert_encoding($s, 'ISO-8859-1', 'UTF-8');
        }
        $s = str_replace('\\', '\\\\', $s);
        $s = str_replace('(', '\\(', $s);
        $s = str_replace(')', '\\)', $s);
        return $s;
    }

    protected function out($s) {
        if ($this->page > 0) {
            $this->pages[$this->page] .= $s . "\n";
        } else {
            $this->buffer .= $s . "\n";
        }
    }

    public function Output($dest = 'I', $name = 'document.pdf') {
        if ($this->page === 0) {
            $this->AddPage();
        }

        // Add Footer if defined
        if (is_callable($this->footerCallback)) {
            for ($p = 1; $p <= $this->page; $p++) {
                $this->page = $p;
                call_user_func($this->footerCallback, $this);
            }
        }

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        // 1. Catalog
        $offsets[1] = strlen($pdf);
        $pdf .= "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";

        // 2. Pages object
        $kids = '';
        $pageObjIds = [];
        $objId = 3;
        for ($i = 1; $i <= count($this->pages); $i++) {
            $pageObjIds[$i] = $objId;
            $kids .= "$objId 0 R ";
            $objId += 2; // page obj + content stream obj
        }

        // Fonts
        $fontObjStart = $objId;
        $fontObjIds = [];
        $kFonts = array_keys($this->fonts);
        foreach ($kFonts as $fIdx => $fKey) {
            $fontObjIds[$fIdx + 1] = $objId++;
        }

        $offsets[2] = strlen($pdf);
        $pdf .= "2 0 obj\n<< /Type /Pages /Kids [" . trim($kids) . "] /Count " . count($this->pages) . " /MediaBox [0 0 595.28 841.89] >>\nendobj\n";

        // Font resources dictionary string
        $fontRes = '';
        foreach ($fontObjIds as $fNum => $fObjId) {
            $fontRes .= "/F$fNum $fObjId 0 R ";
        }

        // Page objects and content streams
        for ($p = 1; $p <= count($this->pages); $p++) {
            $pId = $pageObjIds[$p];
            $cId = $pId + 1;

            // Page obj
            $offsets[$pId] = strlen($pdf);
            $pdf .= "$pId 0 obj\n<< /Type /Page /Parent 2 0 R /Resources << /Font << $fontRes >> >> /Contents $cId 0 R >>\nendobj\n";

            // Content stream
            $stream = trim($this->pages[$p]);
            $streamLen = strlen($stream);
            $offsets[$cId] = strlen($pdf);
            $pdf .= "$cId 0 obj\n<< /Length $streamLen >>\nstream\n" . $stream . "\nendstream\nendobj\n";
        }

        // Font objects
        foreach ($kFonts as $fIdx => $fKey) {
            $fNum = $fIdx + 1;
            $fObjId = $fontObjIds[$fNum];
            $baseFont = $this->fonts[$fKey]['name'];

            $offsets[$fObjId] = strlen($pdf);
            $pdf .= "$fObjId 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /$baseFont /Encoding /WinAnsiEncoding >>\nendobj\n";
        }

        // XRef table
        $xrefStart = strlen($pdf);
        $totalObjs = $objId;
        $pdf .= "xref\n0 $totalObjs\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i < $totalObjs; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }

        // Trailer
        $pdf .= "trailer\n<< /Size $totalObjs /Root 1 0 R >>\n";
        $pdf .= "startxref\n$xrefStart\n%%EOF\n";

        if ($dest === 'S') {
            return $pdf;
        } elseif ($dest === 'F') {
            return file_put_contents($name, $pdf) !== false;
        } elseif ($dest === 'D') {
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $name . '"');
            header('Content-Length: ' . strlen($pdf));
            header('Cache-Control: private, max-age=0, must-revalidate');
            header('Pragma: public');
            echo $pdf;
            exit;
        } else {
            // Inline
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="' . $name . '"');
            header('Content-Length: ' . strlen($pdf));
            header('Cache-Control: private, max-age=0, must-revalidate');
            header('Pragma: public');
            echo $pdf;
            exit;
        }
    }
}
