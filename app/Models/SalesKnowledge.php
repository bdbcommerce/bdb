<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * What the sales assistant may use (Admin → Sales assistant → Knowledge). It never invents beyond this:
 * empty kinds (e.g. Asl Belgi categories, device specs) make it say "the manager will confirm".
 */
class SalesKnowledge extends Model
{
    protected $table = 'sales_knowledge';

    protected $fillable = ['kind', 'title', 'body', 'is_active', 'sort'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort' => 'integer'];
    }

    /** kind => [menu label, what to write, title label, body label] */
    public const KINDS = [
        'product' => ['Product questions', 'What the assistant asks for each product, one question per line, in order. Add notes for the assistant below the questions.', 'Product', 'Questions (one per line) and notes'],
        'business' => ['Business sets', 'For each type of business: what to offer as a set, one item per line. The assistant suggests only the items that fit.', 'Type of business', 'Items to offer (one per line) and notes'],
        'marking' => ['Asl Belgi categories', 'Product categories that require Asl Belgi marking. The assistant offers marking only for these; when a category is not listed it says the manager will confirm.', 'Category', 'Details (from date, notes)'],
        'software' => ['Our software', 'Software BDB sells or installs, and for which business. The assistant offers only what is here.', 'Software', 'For whom, what it does'],
        'device' => ['Device specs', 'Real specifications of the devices we sell or service (one model per entry). The assistant uses only these, e.g. to find the ribbon width or core size for a printer model.', 'Model', 'Specifications (one per line, e.g. "Print width: 104 mm")'],
        'glossary' => ['Customer words', 'How customers describe things in their own words, and the real product. One per line: "words = product".', 'Topic', 'Lines "customer words = product"'],
        'reply' => ['Standard replies', 'Fixed answers: job seekers, suppliers, spam, closing phrases. The assistant adapts the wording and language.', 'Situation', 'What to answer'],
    ];

    /** Filled in by the migration from the owner's answers (2026-11-22). Kinds the owner must fill are left empty. */
    public const DEFAULTS = [
        'product' => [
            ['Labels', "Where will you use them? Choices: shop prices / products · scales (weighed goods) · warehouse / shipping · Asl Belgi marking · textile labels (nylon, satin…) · pharmacy / medical · food: freezer or oily · outdoor / industrial · other (describe)\nSize: width × height in mm (if unknown: a photo of the current label or roll, or the printer model)\nPrinter model\nBlank, or printed with a logo / colours?\nQuantity: labels per day or per month, or rolls per month\n\nNotes: the use decides the type — thermal or thermal transfer, paper or synthetic. Do not state the type as a fact; the manager confirms it."],
            ['Ribbons', "What does it print on: paper, synthetic or textile labels? (this decides wax / wax-resin / resin)\nRibbon width and length, if known (e.g. 110 mm × 300 m)\nPrinter model (if unknown: ask for a photo of the printer and read the model from it)\nQuantity per month\n\nNotes: width, length, core and ink side only from Device specs for that model — never guessed."],
            ['Barcode scanners', "Where will it be used: shop checkout, warehouse, pharmacy, Asl Belgi codes, codes on phone screens?\nWired or wireless?\nHandheld, or fixed on the counter / table?\nWhich program will it work with (1C, MoySklad, own POS…), and how many?\n\nNotes: Asl Belgi codes and phone screens need a 2D scanner. Explain simply when the customer is not sure."],
            ['Label printers', "What will you print (product / price labels, shipping, Asl Belgi codes, textile labels…)?\nAbout how many labels a day, and the label width?\nConnection: USB, network (LAN) or Wi-Fi?\nWhich program will you print from, and how many printers?\n\nNotes: volume decides desktop or industrial."],
            ['Setup service', "Which device: brand and model? (if unknown: a photo of the device or its label)\nWhat is needed: installation, driver, connecting to 1C or another program, calibration, not printing, an error (a photo of the screen helps)?\nOn-site visit or remote help?\nAddress (city) and how urgent?"],
            ['Receipt printers', "Paper width 58 or 80 mm? (if unknown: what it is for, or a photo of the current roll)\nConnection: USB, LAN, Wi-Fi or Bluetooth?\nWhich program / POS software prints the receipts?\nHow many printers, and how many receipt rolls a month?"],
            ['Data collection terminals (TSD / PDA)', "What is it for: inventory, receiving, picking, Asl Belgi codes, price checking?\nWhere: shop floor, warehouse, freezer, outdoors?\nWhich program will run on it (1C, MoySklad, own WMS…)?\nHow many terminals?"],
            ['RFID', "What do you want to track: clothing, equipment / assets, warehouse pallets, access…?\nWhat is needed: tags / labels, readers (handheld or gates), RFID printers, or a full system?\nHow far must it read, and on what surface (metal, liquid, textile)?\nQuantity (tags per month or items), and which software will use the data?"],
            ['Asl Belgi marking', "What products: the product category? (check Asl Belgi categories)\nYou are a manufacturer, importer, wholesaler or retailer?\nWhere are you now: not started, registered in the system, or already marking and need equipment / help?\nHow many codes a day or month, and which printers, scanners and software do you already have?"],
        ],
        'business' => [
            ['Retail shop / supermarket', "2D checkout scanner (also reads Asl Belgi codes and phone screens)\n80 mm receipt printer and receipt paper\nLabel-printing scales and scale labels\nPrice-label printer and labels\nPOS terminal, and a TSD for stocktaking\n\nAsk which software they use."],
            ['Warehouse / logistics', "Data collection terminals (TSD)\nIndustrial or desktop label printer (by volume)\nShipping / box / pallet labels and ribbons\nScanners, including long-range ones for high shelves"],
            ['Production / manufacturer', "Asl Belgi marking set — only if their product category is in Asl Belgi categories\nIndustrial label printer\nLabels and resin / wax-resin ribbons\n2D scanners or TSD to check codes"],
            ['Pharmacy', "2D scanner (DataMatrix on medicines)\nReceipt printer and receipt paper\nPrice-label printer and labels\nTSD for receiving and stock\nComputer, laptop or POS"],
            ['Clothing / textile', "Textile labels (nylon, satin…) and ribbons\nTag / price-label printer\nCheckout: 2D scanner, receipt printer, POS\nRFID"],
            ['HoReCa (cafes, restaurants, fast food)', "Receipt printer and kitchen printer, receipt paper\nPOS terminal or computer\nLabel printer and labels for food / delivery\nScanner — they sell Asl Belgi goods (cigarettes, water…), so a 2D scanner\nSoftware for a new place without one — only from Our software"],
        ],
        'glossary' => [
            ['Scanners and terminals', "scanner with a display / screen / keyboard = data collection terminal (TSD / PDA)\nscanner with Android = data collection terminal (TSD / PDA)\nterminal for inventory = data collection terminal (TSD / PDA)"],
            ['Printers and supplies', "sticker printer / etiketka printer = label printer\ncheque printer / check printer = receipt printer\nlenta / ink tape for a label printer = ribbon"],
        ],
        'reply' => [
            ['Job seekers', 'Thank them and give the careers page of the website (vacancies and the CV form). No request to the manager.'],
            ['Suppliers offering goods', "Thank them and ask them to send the offer to the company's email (from the company profile). No request to the manager."],
            ['Spam / wrong number', 'One short polite line. No request.'],
        ],
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public static function label(string $kind): string
    {
        return self::KINDS[$kind][0] ?? $kind;
    }

    /** The KNOWLEDGE block of the prompt: every kind, active entries in order (an empty kind says so). */
    public static function forPrompt(): string
    {
        $all = self::active()->orderBy('sort')->orderBy('id')->get()->groupBy('kind');
        $out = [];
        foreach (self::KINDS as $kind => [$label]) {
            $entries = $all->get($kind, collect());
            $out[] = "## {$label}\n".($entries->isEmpty()
                ? '(empty — do not state anything about this; say the manager will confirm)'
                : $entries->map(fn (self $e) => "### {$e->title}\n".trim($e->body))->implode("\n\n"));
        }

        return implode("\n\n", $out);
    }
}
