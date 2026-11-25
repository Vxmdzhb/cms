<?php
declare(strict_types=1);

namespace AstrX\Page;

use AstrX\I18n\Translator;

final class Page
{
    public function __construct(
        public readonly int $id,
        public readonly string $urlId,
        public readonly bool $i18n,
        public readonly string $fileName,
        public readonly bool $template,
        public readonly bool $controller,
        public readonly bool $hidden,
        public readonly bool $comments = false,
        public readonly bool $apiEnabled = false,
        /** @var list<array{id:int,url_id:string,i18n:bool,file_name:string}> */
        public readonly array $ancestors = [],
        public readonly bool $index = false,
        public readonly bool $follow = false,
        public readonly string $title = '',
        public readonly string $description = '',
        /** @var list<array{keyword:string,i18n:int|bool}> */
        public readonly array $keywords = [],
        public readonly string $templateFileName = '',
        /** Optional module that owns this page ('' = core / always-on). */
        public readonly string $module = '',
    ) {}

    public function resolvedUrlId(Translator $translator): string
    {
        return $this->i18n ? $translator->t($this->urlId, fallback: $this->urlId) : $this->urlId;
    }
}
