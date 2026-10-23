<?php

use AmjadIqbal\SqlDashboard\Execution\Result;
use AmjadIqbal\SqlDashboard\Support\Csv;

it('neutralises cells a spreadsheet would run as a formula', function () {
    expect(Csv::cell('=HYPERLINK("http://x")'))->toBe("'=HYPERLINK(\"http://x\")")
        ->and(Csv::cell('+1+1'))->toBe("'+1+1")
        ->and(Csv::cell('@SUM(A1)'))->toBe("'@SUM(A1)")
        ->and(Csv::cell('-2+3'))->toBe("'-2+3")
        ->and(Csv::cell('-12.5'))->toBe('-12.5')
        ->and(Csv::cell(-3))->toBe('-3')
        ->and(Csv::cell(null))->toBe('')
        ->and(Csv::cell(true))->toBe('true');
});

it('writes a header and rows', function () {
    $out = fopen('php://memory', 'w+');
    Csv::write($out, new Result(['id', 'name'], [[1, 'Ada, "the first"'], [2, null]]));
    rewind($out);

    expect(stream_get_contents($out))->toBe("\xEF\xBB\xBFid,name\n1,\"Ada, \"\"the first\"\"\"\n2,\n");
});
