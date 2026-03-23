<?php

declare(strict_types=1);

namespace PHPVector\BM25\StopWords;

/**
 * Italian stop words provider.
 *
 * Includes articles, prepositions, pronouns, conjunctions, auxiliary verbs,
 * and other high-frequency words that carry little semantic value.
 */
final class ItalianStopWords implements StopWordsProviderInterface
{
    public function getStopWords(): array
    {
        return self::WORDS;
    }

    /**
     * Static access for use without instantiation.
     *
     * @return string[]
     */
    public static function words(): array
    {
        return self::WORDS;
    }

    private const WORDS = [
        // Articoli determinativi e indeterminativi
        'il', 'lo', 'la', 'i', 'gli', 'le', 'l',
        'un', 'uno', 'una',

        // Preposizioni semplici e articolate
        'di', 'a', 'da', 'in', 'con', 'su', 'per', 'tra', 'fra',
        'del', 'dello', 'della', 'dei', 'degli', 'delle',
        'al', 'allo', 'alla', 'ai', 'agli', 'alle',
        'dal', 'dallo', 'dalla', 'dai', 'dagli', 'dalle',
        'nel', 'nello', 'nella', 'nei', 'negli', 'nelle',
        'sul', 'sullo', 'sulla', 'sui', 'sugli', 'sulle',

        // Pronomi personali
        'io', 'tu', 'lui', 'lei', 'noi', 'voi', 'loro', 'esso', 'essa', 'essi', 'esse',
        'me', 'te', 'mi', 'ti', 'ci', 'vi', 'si', 'ne', 'lo', 'la', 'li', 'le',

        // Pronomi e aggettivi dimostrativi
        'questo', 'questa', 'questi', 'queste', 'quello', 'quella', 'quelli', 'quelle',
        'ciò', 'stesso', 'stessa', 'stessi', 'stesse',

        // Pronomi e aggettivi possessivi
        'mio', 'mia', 'miei', 'mie', 'tuo', 'tua', 'tuoi', 'tue',
        'suo', 'sua', 'suoi', 'sue', 'nostro', 'nostra', 'nostri', 'nostre',
        'vostro', 'vostra', 'vostri', 'vostre', 'loro', 'proprio', 'propria', 'propri', 'proprie',

        // Pronomi e aggettivi interrogativi/esclamativi
        'che', 'chi', 'quale', 'quali', 'quanto', 'quanta', 'quanti', 'quante',
        'cosa', 'come', 'dove', 'quando', 'perché',

        // Pronomi e aggettivi indefiniti
        'alcuno', 'alcuna', 'alcuni', 'alcune', 'qualche', 'qualcuno', 'qualcosa',
        'nessuno', 'nessuna', 'niente', 'nulla', 'ogni', 'ognuno', 'ciascuno', 'ciascuna',
        'tutto', 'tutta', 'tutti', 'tutte', 'tanto', 'tanta', 'tanti', 'tante',
        'molto', 'molta', 'molti', 'molte', 'poco', 'poca', 'pochi', 'poche',
        'altro', 'altra', 'altri', 'altre', 'certo', 'certa', 'certi', 'certe',
        'tale', 'tali', 'vario', 'varia', 'vari', 'varie',

        // Pronomi relativi
        'cui', 'quale', 'quali',

        // Congiunzioni coordinanti
        'e', 'ed', 'o', 'oppure', 'ma', 'però', 'tuttavia', 'anzi', 'né',
        'sia', 'pure', 'anche', 'inoltre', 'dunque', 'quindi', 'perciò', 'allora',

        // Congiunzioni subordinanti
        'che', 'se', 'perché', 'poiché', 'giacché', 'siccome', 'affinché',
        'benché', 'sebbene', 'nonostante', 'mentre', 'quando', 'finché',
        'come', 'così', 'dove', 'quanto',

        // Verbo essere (indicativo presente, passato prossimo, imperfetto)
        'sono', 'sei', 'è', 'siamo', 'siete',
        'era', 'eri', 'eravamo', 'eravate', 'erano',
        'sarò', 'sarai', 'sarà', 'saremo', 'sarete', 'saranno',
        'stato', 'stata', 'stati', 'state', 'essere',

        // Verbo avere (indicativo presente, passato prossimo, imperfetto)
        'ho', 'hai', 'ha', 'abbiamo', 'avete', 'hanno',
        'aveva', 'avevi', 'avevo', 'avevamo', 'avevate', 'avevano',
        'avrò', 'avrai', 'avrà', 'avremo', 'avrete', 'avranno',
        'avuto', 'avere',

        // Verbo fare (forme comuni)
        'faccio', 'fai', 'fa', 'facciamo', 'fate', 'fanno',
        'fatto', 'fare',

        // Verbi modali e ausiliari comuni
        'posso', 'puoi', 'può', 'possiamo', 'potete', 'possono', 'potere',
        'devo', 'devi', 'deve', 'dobbiamo', 'dovete', 'devono', 'dovere',
        'voglio', 'vuoi', 'vuole', 'vogliamo', 'volete', 'vogliono', 'volere',

        // Avverbi comuni
        'non', 'più', 'già', 'ancora', 'sempre', 'mai', 'ora', 'adesso',
        'poi', 'prima', 'dopo', 'qui', 'qua', 'lì', 'là', 'sopra', 'sotto',
        'dentro', 'fuori', 'molto', 'poco', 'troppo', 'meno', 'bene', 'male',
        'così', 'solo', 'soltanto', 'insieme', 'circa', 'quasi', 'appena', 'forse',
    ];
}
