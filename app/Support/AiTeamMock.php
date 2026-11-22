<?php

namespace App\Support;

/**
 * MOCK-UP data for the "AI Sales & Support Team" menu group: the proposed AI employees with their default
 * prompts and temperament, and sample training / client-portrait data. Nothing here is used by the engine yet —
 * it shows the owner the structure so it can be agreed before it is built.
 */
class AiTeamMock
{
    public static function audits(): array
    {
        return [
            ['id' => 418, 'employee' => 'Malika', 'customer' => 'Akmal · Oila Market', 'score' => 3, 'cause' => 'Prompt rule',
                'note' => 'Suggested a model before asking about the cash desks.', 'fix' => 'Prompt v4 draft + test case'],
            ['id' => 411, 'employee' => 'Malika', 'customer' => 'Dilnoza · pharmacy', 'score' => 5, 'cause' => null,
                'note' => 'Found the need, explained marking, handed over with a full summary.', 'fix' => null],
            ['id' => 405, 'employee' => 'Malika', 'customer' => 'Bobur', 'score' => 2, 'cause' => 'Missing knowledge',
                'note' => 'Could not say whether the scale works with 1C — stayed silent twice.', 'fix' => 'Knowledge: "Our software"'],
        ];
    }

    public static function gaps(): array
    {
        return [
            ['topic' => 'Does the scale work with 1C / Smartup?', 'asked' => 7, 'last' => 'today', 'kind' => 'Our software'],
            ['topic' => 'Receipt printer prints blank paper', 'asked' => 5, 'last' => 'yesterday', 'kind' => 'Device specs'],
            ['topic' => 'Which marking categories need a 2D scanner', 'asked' => 4, 'last' => '20.11', 'kind' => 'Asl Belgi categories'],
            ['topic' => 'Delivery to the regions — how long', 'asked' => 3, 'last' => '19.11', 'kind' => 'Standard replies'],
        ];
    }
}
