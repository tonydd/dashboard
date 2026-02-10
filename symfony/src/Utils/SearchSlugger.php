<?php

namespace App\Utils;

use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\String\AbstractUnicodeString;

class SearchSlugger implements SluggerInterface
{
    private SluggerInterface $slugger;

    public function __construct()
    {
        // Langue 'fr' pour un meilleur traitement des accents français
        $this->slugger = new AsciiSlugger('fr');
    }

    public function slug(string $string, string $separator = '-', ?string $locale = null): AbstractUnicodeString
    {
        return $this->slugger->slug($string, $separator, $locale)->lower();
    }
}
