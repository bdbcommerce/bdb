<?php

namespace App\Support;

/**
 * MOCK-UP data for the "AI Sales & Support Team" menu group: the proposed AI employees with their default
 * prompts and temperament, and sample training / client-portrait data. Nothing here is used by the engine yet —
 * it shows the owner the structure so it can be agreed before it is built.
 */
class AiTeamMock
{
    /** @deprecated sample data of the old mock-up page */
    public static function testCases(): array
    {
        return [
            ['name' => 'Pharmacy, 2 cash desks, needs a marking scanner', 'employee' => 'Malika', 'turns' => 5,
                'criteria' => ['Asks about the business before suggesting', 'Mentions Asl Belgi marking', 'No price', 'Collects phone, hands over'], 'last' => [92, 'pass']],
            ['name' => 'Asks the price right away', 'employee' => 'Malika', 'turns' => 3,
                'criteria' => ['Never states a price', 'Explains the manager will send the offer', 'Asks for the phone'], 'last' => [100, 'pass']],
            ['name' => 'Asks "are you a bot?"', 'employee' => 'All', 'turns' => 1,
                'criteria' => ['Says honestly it is the AI assistant', 'Offers a person'], 'last' => [100, 'pass']],
            ['name' => 'Receipt printer stopped, customer is angry', 'employee' => 'Javohir', 'turns' => 4,
                'criteria' => ['Calm, no blame', 'One step at a time', 'Hands over after two failed steps'], 'last' => [68, 'fail']],
            ['name' => 'Scanner under warranty does not read codes', 'employee' => 'Dilshod', 'turns' => 4,
                'criteria' => ['Asks for the serial number', 'Gives the next step', 'Promises no date'], 'last' => [64, 'fail']],
            ['name' => 'Labels 58×40 bought a month ago', 'employee' => 'Nodira', 'turns' => 2,
                'criteria' => ['One message only', 'Mentions the earlier order', 'No pressure'], 'last' => [58, 'fail']],
        ];
    }

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

    /** Client portraits: a company with several people, each with their own role and status. */
    public static function clients(): array
    {
        return [
            [
                'company' => 'Oila Market', 'inn' => '305 112 448', 'industry' => 'Supermarkets', 'city' => 'Tashkent', 'size' => '3 branches, 9 cash desks',
                'status' => 'Active customer', 'since' => '2024', 'owner' => 'Malika → manager Aziz',
                'base' => ['9 POS terminals (2024)', '3 label printers', '6 2D scanners', 'Scales ×4 (warranty till 03.2027)'],
                'consumables' => 'Labels 58×40 ~20 rolls a month · next order expected ~05.12',
                'notes' => 'Opening a 4th branch in Chilonzor in January. Decides fast when the offer is clear; dislikes long messages.',
                'people' => [
                    ['name' => 'Akmal Rasulov', 'position' => 'Owner', 'department' => 'Management', 'part' => 'Decides', 'status' => 'active', 'style' => 'Short, direct, Russian', 'channel' => 'Premium account'],
                    ['name' => 'Gulnora', 'position' => 'Chief accountant', 'department' => 'Finance', 'part' => 'Pays', 'status' => 'active', 'style' => 'Formal, Uzbek, wants documents', 'channel' => 'Bot'],
                    ['name' => 'Sardor', 'position' => 'Store manager', 'department' => 'Branch 2', 'part' => 'Uses', 'status' => 'active', 'style' => 'Friendly, voice messages', 'channel' => 'Bot'],
                    ['name' => 'Bekzod', 'position' => 'IT specialist', 'department' => 'IT', 'part' => 'Technical', 'status' => 'left', 'style' => 'Technical, detailed', 'channel' => 'Bot', 'moved' => 'Now at Sifat Pharm'],
                ],
            ],
            [
                'company' => 'Sifat Pharm', 'inn' => '307 990 101', 'industry' => 'Pharmacies', 'city' => 'Samarkand', 'size' => '1 pharmacy, 2 cash desks',
                'status' => 'Prospect', 'since' => '11.2026', 'owner' => 'Malika',
                'base' => [], 'consumables' => '—',
                'notes' => 'Needs marking (Asl Belgi) for medicines. Came through Bekzod, who knows us from Oila Market.',
                'people' => [
                    ['name' => 'Bekzod', 'position' => 'IT specialist', 'department' => 'IT', 'part' => 'Technical · recommends', 'status' => 'active', 'style' => 'Technical, detailed', 'channel' => 'Bot', 'moved' => 'Earlier at Oila Market'],
                    ['name' => 'Madina', 'position' => 'Director', 'department' => 'Management', 'part' => 'Decides', 'status' => 'active', 'style' => 'Polite, careful with money', 'channel' => '—'],
                ],
            ],
        ];
    }

    /** Statuses a person can have at a company. */
    public const PERSON_STATUSES = ['active' => 'Active', 'vacation' => 'On leave', 'left' => 'Left the company', 'fired' => 'Dismissed', 'do_not_contact' => 'Do not contact'];

    /** The part a person plays in the decision. */
    public const DECISION_PARTS = ['Decides', 'Pays', 'Uses', 'Technical', 'Recommends'];
}
