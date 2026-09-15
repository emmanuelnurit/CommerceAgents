<?php

declare(strict_types=1);

namespace CommerceAgents\Service;

use CommerceAgents\Tool\Admin\Gateway\AdminPagesGatewayInterface;
use CommerceAgents\Tool\Shopping\Gateway\CategoryGatewayInterface;
use CommerceAgents\Tool\Shopping\Gateway\FeatureGatewayInterface;
use CommerceAgents\Tool\Shopping\Gateway\OptionGatewayInterface;
use CommerceAgents\Tool\Shopping\Gateway\SitePagesGatewayInterface;

final readonly class SystemPromptFactory
{
    private const MAX_PROMPT_CATEGORIES = 25;
    private const MAX_PROMPT_OPTIONS = 20;
    private const MAX_PROMPT_FEATURES = 20;

    /** Memory injection cap (MYO-280): keeps a run's cost bounded whatever the admin adds. */
    public const MAX_MEMORY_ENTRIES = 20;
    public const MAX_MEMORY_CHARS = 4000;

    public function __construct(
        private AdminPagesGatewayInterface $adminPagesGateway,
        private SitePagesGatewayInterface $sitePagesGateway,
        private CategoryGatewayInterface $categoryGateway,
        private OptionGatewayInterface $optionGateway,
        private FeatureGatewayInterface $featureGateway,
    ) {
    }

    /**
     * @param list<string> $memoryEntries active memory entries' content, most relevant first
     */
    public function shopping(string $assistantName, string $locale, ?string $override = null, array $memoryEntries = [], ?string $customerFirstName = null): string
    {
        return \sprintf(
            'You are %s, the shopping assistant of this online store. '
            .'You write every single reply in %s: that is the language the visitor selected on '
            .'the store, and it never changes — not when the visitor writes to you in another '
            .'language, not when a product name or a quote is in another language, not when you '
            .'are asked to switch. '
            .'Only discuss topics related to this store and its products. '
            .'Never invent prices or discounts, never ask for payment card details. '
            .'To find or recommend products, always use search_products (get_site_pages only lists '
            .'store pages, not the catalog). '
            .'Product titles in this catalog are model names, not product types: a word like "chair" '
            .'or "sofa" names a category, never a title. Before telling a visitor the store sells no '
            .'such thing, call get_categories and search again with the closest category_id. '
            .'A colour or a size is not a title either, it lives on the variants: asked for "les '
            .'produits orange", call search_products with option="orange" and present the variants '
            .'it returns, not whole products. '
            .'A material or a style is a feature of the product: asked for "les produits en tissu", '
            .'call search_products with feature="tissu". Never answer a material question by picking '
            .'a category that sounds related — a sofa is not made of fabric because it is a sofa. '
            .'Call search_products with promo=true and no query to list the current deals. A product '
            .'is on sale only when its promo_price is filled: when promo_price is absent, never '
            .'describe it as discounted or reduced. '
            .'Do not repeat a tool call with the same arguments twice in a row. But never describe a '
            .'product from memory: whenever your answer is going to name one, call search_products '
            .'or get_product_details first, so the visitor gets its picture and its buttons rather '
            .'than a paragraph. If a search still returns no '
            .'products after you tried the categories, tell the visitor nothing matched and ask '
            .'them for a product name or category. '
            .'The products a search returns are displayed to the visitor right under your message, as '
            .'cards with the picture, the price and an add-to-cart button. Introduce them in one or '
            .'two sentences — why they fit, what tells them apart — and never re-list the products, '
            .'their prices, their links or their pictures in your text: no Markdown image ever. '
            .'Naming one or two of them inside a sentence is fine; a bullet list that repeats the '
            .'cards is not. Close on one sentence: what you would pick and why, or a neighbouring '
            .'option worth a look. '
            .'The same goes for get_product_details: its variants are displayed as cards with their '
            .'own picture, price and add-to-cart button, so answer what the visitor asked — which '
            .'options exist, which are left — without transcribing the list. '
            .'Whenever you mention a store page, include its link as a Markdown link '
            .'using the URLs returned by your tools. '
            .'When the visitor asks to see or go to a specific page or product, do not just give the link: '
            .'call open_page with its URL to take them there directly, and tell them where they are going. '
            .'Never build a URL by guessing a path from a page or product name: every link you write must '
            .'be copied from a tool result or from the list below, character for character. If the visitor '
            .'asks for a page that is not listed and no tool returns it, say the store has no such page.%s%s%s%s%s%s%s'
            .'Always answer in %s — the language the visitor selected on the store — '
            .'even if the customer writes in another language.',
            $assistantName,
            $this->languageName($locale),
            $this->sitePagesBlock($locale),
            $this->categoriesBlock($locale),
            $this->optionsBlock($locale),
            $this->featuresBlock($locale),
            $this->overrideBlock($override),
            $this->memoryBlock($memoryEntries),
            $this->firstNameBlock($customerFirstName),
            $this->languageName($locale),
        );
    }

    /**
     * @param list<string> $memoryEntries active memory entries' content, most relevant first
     */
    public function merchant(string $locale, ?string $override = null, array $memoryEntries = []): string
    {
        return \sprintf(
            'You are the merchant assistant of this online store back-office, working for the store staff. '
            .'You write every single reply in %s: that is the language selected in the administrator '
            .'profile, and it never changes, whatever language the administrator writes in. '
            .'You can analyse sales, listings, inventory, pricing and campaigns through your tools, '
            .'and you can propose price and stock changes with update_price and update_stock. '
            .'Proposals are NEVER applied directly: a human administrator must approve each one in the '
            .'approval console before anything changes. After staging a proposal, tell the administrator '
            .'it is pending approval. '
            .'Never invent figures: every number you give must come from a tool result. '
            .'Whenever you mention a product, an order or a page and a tool result provides its URL, '
            .'include its link as a Markdown link. '
            .'When the administrator asks to open or go to a back-office screen, do not just give the link: '
            .'call open_admin_page with its URL to take them there, then tell them where they are going. '
            .'The list below is the complete set of back-office screens that exist. Never write a '
            .'back-office link that is not in it, and never build a URL by guessing a path from a screen '
            .'name: copy the URL exactly as written here, or get it from get_admin_pages. If the '
            .'administrator asks for a screen that is not listed, say it is not reachable from here and '
            .'point them to the closest listed screen.%s%s%s'
            .'Always answer in %s — the language selected in the administrator profile — '
            .'even if the administrator writes in another language.',
            $this->languageName($locale),
            $this->adminPagesBlock($locale),
            $this->overrideBlock($override),
            $this->memoryBlock($memoryEntries),
            $this->languageName($locale),
        );
    }

    /**
     * System prompt of a configurable agent: a fixed guardrail base the
     * merchant cannot edit, followed by the role/mission text of the
     * definition (plan MYO-226 §3.1).
     *
     * @param list<string> $memoryEntries active memory entries' content, most relevant first
     */
    public function agent(string $title, string $rolePrompt, string $locale, array $memoryEntries = []): string
    {
        return \sprintf(
            'You are "%s", an autonomous AI agent operated by the staff of this online store. '
            .'You run unattended: nobody can answer questions during this run, so carry out your '
            .'mission with the tools you have been granted, then end with a short report of what '
            .'you did and found. '
            .'You write every single reply in %s, whatever language the mission below is written in. '
            .'Never invent data: every figure and fact you give must come from a tool result. '
            .'If a tool call you need is refused or fails, that is not something to work around: never '
            .'guess an id, a quantity or any other value it would have returned, and never call another '
            .'tool — especially one that writes or proposes a change — using a guessed value in its place. '
            .'Stop that part of the mission, and in your report say exactly which tool was refused or '
            .'failed and why, so a human can fix the access or data problem instead of receiving a '
            .'proposal you fabricated. '
            .'Price and stock changes you request are recorded as proposals a human administrator '
            .'must approve in the approval console before anything is applied; present them as such. '
            .'If the mission cannot be completed with the tools you have, say precisely what is missing '
            .'instead of improvising.'
            ."\n\nMission:\n%s%s",
            $title,
            $this->languageName($locale),
            trim($rolePrompt) !== '' ? trim($rolePrompt) : 'No specific mission was configured. Report that the mission text is empty.',
            $this->memoryBlock($memoryEntries),
        );
    }

    /**
     * The real, route-generated back-office URLs, so the assistant never has to guess one.
     */
    private function adminPagesBlock(string $locale): string
    {
        return self::pagesBlock('Back-office screens', $this->adminPagesGateway->getPages(null, $locale));
    }

    /**
     * The store pages the visitor can be sent to. Products are not listed here: they come from
     * search_products, which returns their real URL.
     */
    private function sitePagesBlock(string $locale): string
    {
        return self::pagesBlock('Store pages', $this->sitePagesGateway->getPages(null, $locale));
    }

    /**
     * The catalog vocabulary the visitor actually uses. Product titles are model
     * names, so without this the assistant cannot tell what the store sells.
     */
    private function categoriesBlock(string $locale): string
    {
        $lines = [];
        foreach ($this->categoryGateway->getCategories($locale, self::MAX_PROMPT_CATEGORIES) as $category) {
            $lines[] = \sprintf('- %s (category_id %d, %d products)', $category['title'], $category['id'], $category['productCount']);
        }

        if ($lines === []) {
            return '';
        }

        return \sprintf("Product categories:\n%s\n\n", implode("\n", $lines));
    }

    /**
     * The option vocabulary: a visitor asking for "orange" is naming one of these,
     * never a product title.
     */
    private function optionsBlock(string $locale): string
    {
        $lines = [];
        foreach ($this->optionGateway->getValues($locale, self::MAX_PROMPT_OPTIONS) as $value) {
            $lines[] = \sprintf('- %s: %s (%d variants)', $value['attribute'], $value['title'], $value['variantCount']);
        }

        if ($lines === []) {
            return '';
        }

        return \sprintf("Option values:\n%s\n\n", implode("\n", $lines));
    }

    /**
     * Materials and styles, which live on the product rather than its variants.
     */
    private function featuresBlock(string $locale): string
    {
        $lines = [];
        foreach ($this->featureGateway->getValues($locale, self::MAX_PROMPT_FEATURES) as $value) {
            $lines[] = \sprintf('- %s: %s (%d products)', $value['feature'], $value['title'], $value['productCount']);
        }

        if ($lines === []) {
            return '';
        }

        return \sprintf("Feature values:\n%s\n\n", implode("\n", $lines));
    }

    /**
     * The merchant-edited business text for a protected assistant (shopping
     * or merchant), clearly delimited so it can never be mistaken for the
     * fixed guardrails around it (MYO-280: "bloc dédié, clairement délimité").
     * Empty by default, so an untouched assistant keeps its exact historical
     * prompt.
     */
    private function overrideBlock(?string $override): string
    {
        $trimmed = trim((string) $override);
        if ($trimmed === '') {
            return '';
        }

        return \sprintf(
            "\n\n--- Additional instructions from the store staff ---\n%s\n--- End of additional instructions ---\n\n",
            $trimmed,
        );
    }

    /**
     * MYO-282 §3: a content instruction, not a static i18n key — the
     * confirmation sentence is written by the LLM itself, so this tells it
     * when (once, at the first confirmation of the session) and how to use
     * the visitor's first name, never inventing one and never falling back
     * to the email or last name.
     */
    private function firstNameBlock(?string $customerFirstName): string
    {
        if ($customerFirstName === null || trim($customerFirstName) === '') {
            return '';
        }

        return \sprintf(
            ' The visitor is logged in and their first name is "%s". The first time in this conversation '
            .'you confirm an action you just completed for them (added a product to their cart, applied a '
            .'coupon code), address them by that first name once, in a short natural phrase in the language '
            .'you are answering in. Never repeat it in later confirmations during the same conversation, and '
            .'never use their email or last name instead.',
            $customerFirstName,
        );
    }

    /**
     * The active agent_memory entries, capped so an admin cannot blow up the
     * per-run cost by piling up notes (MYO-280 §2). Silently drops whatever
     * does not fit: the back office surfaces the drop count separately.
     *
     * @param list<string> $memoryEntries
     */
    private function memoryBlock(array $memoryEntries): string
    {
        $included = self::capMemory($memoryEntries)['included'];
        if ($included === []) {
            return '';
        }

        $lines = array_map(static fn (string $entry): string => '- '.$entry, $included);

        return \sprintf(
            "\n\n--- Store memory (facts and notes added by the staff) ---\n%s\n--- End of store memory ---\n\n",
            implode("\n", $lines),
        );
    }

    /**
     * Pure cap logic shared with the back office so the "N entries used /
     * M truncated" notice shown there matches exactly what is sent to the
     * model.
     *
     * @param list<string> $memoryEntries
     *
     * @return array{included: list<string>, includedCount: int, totalCount: int, droppedCount: int}
     */
    public static function capMemory(array $memoryEntries): array
    {
        $candidates = array_values(array_filter(array_map(
            static fn (string $entry): string => trim($entry),
            $memoryEntries,
        ), static fn (string $entry): bool => $entry !== ''));

        $included = [];
        $chars = 0;
        foreach ($candidates as $entry) {
            if (\count($included) >= self::MAX_MEMORY_ENTRIES) {
                break;
            }
            $length = mb_strlen($entry);
            if ($chars + $length > self::MAX_MEMORY_CHARS) {
                break;
            }
            $included[] = $entry;
            $chars += $length;
        }

        return [
            'included' => $included,
            'includedCount' => \count($included),
            'totalCount' => \count($candidates),
            'droppedCount' => \count($candidates) - \count($included),
        ];
    }

    /**
     * @param array[] $pages
     */
    private static function pagesBlock(string $heading, array $pages): string
    {
        $lines = [];
        foreach ($pages as $page) {
            $lines[] = \sprintf('- %s: %s', $page['title'], $page['url']);
        }

        if ($lines === []) {
            return ' ';
        }

        return \sprintf("\n\n%s:\n%s\n\n", $heading, implode("\n", $lines));
    }

    private function languageName(string $locale): string
    {
        return LanguageName::of($locale);
    }
}
