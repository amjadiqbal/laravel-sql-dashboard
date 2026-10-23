<?php

declare(strict_types=1);

namespace AmjadIqbal\SqlDashboard\Charts;

/**
 * Draws a Chart as inline SVG on the server: no JavaScript library, no CDN,
 * nothing for the browser to fetch. Every point has a <title> tooltip.
 * Colours are solid and cool-toned so they read on light and dark pages.
 */
final class SvgChart
{
    public const PALETTE = ['#10B981', '#0EA5E9', '#8B5CF6', '#EC4899', '#6366F1', '#06B6D4', '#84CC16', '#64748B'];

    private const W = 800;

    private const H = 300;

    private const L = 64;

    private const R = 16;

    private const T = 16;

    private const B = 56;

    public static function render(Chart $chart): string
    {
        return $chart->type === 'pie' ? self::pie($chart) : self::axes($chart);
    }

    private static function axes(Chart $chart): string
    {
        $values = [];
        foreach ($chart->series as $s) {
            foreach ($s['values'] as $v) {
                if ($v !== null) {
                    $values[] = $v;
                }
            }
        }
        $min = min(0.0, $values === [] ? 0.0 : min($values));
        $max = max(0.0, $values === [] ? 1.0 : max($values));
        [$lo, $hi, $step] = self::niceScale($min, $max);
        $pw = self::W - self::L - self::R;
        $ph = self::H - self::T - self::B;
        $y = fn (float $v): float => self::T + $ph - ($v - $lo) / ($hi - $lo) * $ph;
        $count = max(1, count($chart->labels));
        $slot = $pw / $count;
        $xLine = fn (int $i): float => self::L + ($count === 1 ? $pw / 2 : $i * $pw / ($count - 1));

        $svg = '<svg class="chart" viewBox="0 0 '.self::W.' '.self::H.'" role="img" aria-label="'
            .self::e(ucfirst($chart->type).' chart of '.implode(', ', array_column($chart->series, 'name'))).'" xmlns="http://www.w3.org/2000/svg">';

        for ($i = 0, $v = $lo; $v <= $hi + $step / 2 && $i < 20; $i++, $v = $lo + $i * $step) {
            $yy = round($y($v), 1);
            $svg .= '<line class="grid" x1="'.self::L.'" x2="'.(self::W - self::R).'" y1="'.$yy.'" y2="'.$yy.'"/>';
            $svg .= '<text class="tick" x="'.(self::L - 8).'" y="'.($yy + 4).'" text-anchor="end">'.self::e(self::format($v)).'</text>';
        }

        $every = (int) ceil($count / 10);
        foreach ($chart->labels as $i => $label) {
            if ($i % $every !== 0) {
                continue;
            }
            $x = $chart->type === 'line' ? $xLine($i) : self::L + ($i + 0.5) * $slot;
            $svg .= '<text class="tick" x="'.round($x, 1).'" y="'.(self::H - self::B + 18).'" text-anchor="middle">'.self::e(self::shorten($label)).'</text>';
        }

        if ($chart->type === 'line') {
            foreach ($chart->series as $si => $s) {
                $color = self::PALETTE[$si % count(self::PALETTE)];
                $points = [];
                $dots = '';
                foreach ($s['values'] as $i => $v) {
                    if ($v === null) {
                        continue;
                    }
                    $x = round($xLine($i), 1);
                    $yy = round($y($v), 1);
                    $points[] = "{$x},{$yy}";
                    $dots .= '<circle cx="'.$x.'" cy="'.$yy.'" r="'.($count > 60 ? 2 : 3.5).'" fill="'.$color.'"><title>'
                        .self::e(($chart->labels[$i] ?? '').' · '.$s['name'].': '.self::format($v)).'</title></circle>';
                }
                $svg .= '<polyline fill="none" stroke="'.$color.'" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" points="'.implode(' ', $points).'"/>'.$dots;
            }
        } else {
            $n = max(1, count($chart->series));
            $group = $slot * 0.8;
            $bw = $group / $n;
            $zero = $y(max($lo, 0.0));
            foreach ($chart->series as $si => $s) {
                $color = self::PALETTE[$si % count(self::PALETTE)];
                foreach ($s['values'] as $i => $v) {
                    if ($v === null) {
                        continue;
                    }
                    $x = self::L + $i * $slot + ($slot - $group) / 2 + $si * $bw;
                    $top = min($zero, $y($v));
                    $h = abs($zero - $y($v));
                    $svg .= '<rect x="'.round($x + 1, 1).'" y="'.round($top, 1).'" width="'.round(max(1, $bw - 2), 1).'" height="'.round(max(0.5, $h), 1).'" rx="2" fill="'.$color.'"><title>'
                        .self::e(($chart->labels[$i] ?? '').' · '.$s['name'].': '.self::format($v)).'</title></rect>';
                }
            }
        }

        $base = round($y(max($lo, 0.0)), 1);
        $svg .= '<line class="axis" x1="'.self::L.'" x2="'.(self::W - self::R).'" y1="'.$base.'" y2="'.$base.'"/>';

        return $svg.'</svg>'.self::legend($chart);
    }

    private static function pie(Chart $chart): string
    {
        $values = array_map(fn (?float $v): float => (float) $v, $chart->series[0]['values'] ?? []);
        $total = array_sum($values);
        $cx = 150;
        $cy = 150;
        $r = 130;
        $svg = '<svg class="chart pie" viewBox="0 0 300 300" role="img" aria-label="Pie chart of '.self::e($chart->series[0]['name'] ?? '').'" xmlns="http://www.w3.org/2000/svg">';
        if ($total <= 0) {
            return $svg.'<circle cx="150" cy="150" r="130" class="grid"/></svg>';
        }
        $angle = -M_PI / 2;
        $p = fn (float $a): string => round($cx + $r * cos($a), 2).' '.round($cy + $r * sin($a), 2);
        foreach ($values as $i => $v) {
            if ($v <= 0) {
                continue;
            }
            $color = self::PALETTE[$i % count(self::PALETTE)];
            $title = '<title>'.self::e(($chart->labels[$i] ?? '').': '.self::format($v).' ('.round($v / $total * 100, 1).'%)').'</title>';
            if ($v >= $total) {
                $svg .= '<circle cx="'.$cx.'" cy="'.$cy.'" r="'.$r.'" fill="'.$color.'">'.$title.'</circle>';
                break;
            }
            $end = $angle + $v / $total * 2 * M_PI;
            $large = $end - $angle > M_PI ? 1 : 0;
            $svg .= '<path class="slice" d="M'.$cx.' '.$cy.' L'.$p($angle).' A'.$r.' '.$r.' 0 '.$large.' 1 '.$p($end).' Z" fill="'.$color.'">'.$title.'</path>';
            $angle = $end;
        }
        $svg .= '</svg>';

        $legend = '<ul class="legend">';
        foreach ($values as $i => $v) {
            $legend .= '<li><i style="background:'.self::PALETTE[$i % count(self::PALETTE)].'"></i>'.self::e($chart->labels[$i] ?? '')
                .' <b>'.self::e(self::format($v)).'</b> <span>'.round($v / $total * 100, 1).'%</span></li>';
        }

        return '<div class="pie-wrap">'.$svg.$legend.'</ul></div>';
    }

    private static function legend(Chart $chart): string
    {
        if (count($chart->series) < 2) {
            return '';
        }
        $out = '<ul class="legend">';
        foreach ($chart->series as $i => $s) {
            $out .= '<li><i style="background:'.self::PALETTE[$i % count(self::PALETTE)].'"></i>'.self::e($s['name']).'</li>';
        }

        return $out.'</ul>';
    }

    /** @return array{0: float, 1: float, 2: float} */
    private static function niceScale(float $min, float $max): array
    {
        if ($max - $min < 1e-12) {
            $max = $min + 1;
        }
        $raw = ($max - $min) / 5;
        $mag = 10 ** floor(log10($raw));
        $step = $mag * match (true) {
            $raw / $mag <= 1 => 1,
            $raw / $mag <= 2 => 2,
            $raw / $mag <= 5 => 5,
            default => 10,
        };

        return [floor($min / $step) * $step, ceil($max / $step) * $step, (float) $step];
    }

    public static function format(float $v): string
    {
        $abs = abs($v);

        return match (true) {
            $abs >= 1e9 => round($v / 1e9, 1).'B',
            $abs >= 1e6 => round($v / 1e6, 1).'M',
            $abs >= 1e4 => round($v / 1e3, 1).'k',
            floor($v) == $v => number_format($v, 0, '.', ','),
            default => rtrim(rtrim(number_format($v, 2, '.', ','), '0'), '.'),
        };
    }

    private static function shorten(string $label): string
    {
        return mb_strlen($label) > 16 ? mb_substr($label, 0, 15).'…' : $label;
    }

    private static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
