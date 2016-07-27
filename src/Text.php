<?php

namespace App;

class Text
{

    /**
     * Escape various characters, and wrap cell values in quotes where required.
     * Also, for multi-valued inputs (i.e. arrays), escape them and also concatenate them with pipes.
     *
     * @param string|array $str The input string or array of strings.
     * @return string
     */
    public static function csvCell($str)
    {
        if (is_array($str)) {
            return self::csvCell(join("|", $str));
        }
        if (is_numeric($str)) {
            return $str;
        }
        return '"' . str_replace(array("\n", "\r", '"'), array('\\n', "\\r", '""'), $str) . '"';
    }

    public static function snakecase($str, $glue = '_')
    {
        $patterns = ['/([a-z\d])([A-Z])/', '/([^_-])([A-Z][a-z])/'];
        return strtolower(preg_replace($patterns, '$1' . $glue . '$2', $str));
    }

    /**
     * Turn a spaced or underscored string to camelcase (with no spaces or underscores).
     *
     * @param string $str
     * @return string
     */
    public static function camelcase($str)
    {
        return str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $str)));
    }

    /**
     * Apply the titlecase filter to a string: removing underscores, uppercasing
     * initial letters, and performing a few common (and not-so-common) word
     * replacements such as initialisms and punctuation.
     *
     * @param string|array $value    The underscored and lowercase string to be
     *                               titlecased, or an array of such strings.
     * @param 'html'|'latex' $format The desired output format.
     * @return string                A properly-typeset title.
     * @todo Get replacement strings from configuration file.
     */
    public static function titlecase($value, $format = 'html')
    {

        /**
         * The mapping of words (and initialisms, etc.) to their titlecased
         * counterparts for HTML output.
         * @var array
         */
        $html_replacements = array(
            'id' => 'ID',
            'cant' => "can't",
            'in' => 'in',
            'at' => 'at',
            'of' => 'of',
            'for' => 'for',
            'sql' => 'SQL',
            'todays' => "Today's",
        );

        /**
         * The mapping of words (and initialisms, etc.) to their titlecased
         * counterparts for LaTeX output.
         * @var array
         */
        $latex_replacements = array(
            'cant' => "can't",
        );

        /**
         * Marshall the correct replacement strings.
         */
        if ('latex' == $format) {
            $replacements = array_merge($html_replacements, $latex_replacements);
        } else {
            $replacements = $html_replacements;
        }

        /**
         * Recurse if neccessary
         */
        if (is_array($value)) {
            return array_map(array(self, 'titlecase'), $value);
        } else {
            $out = ucwords(preg_replace('|_|', ' ', $value));
            foreach ($replacements as $search => $replacement) {
                $out = preg_replace("|\b$search\b|i", $replacement, $out);
            }
            return trim($out);
        }
    }

    /**
     * Split a string on line boundaries.
     *
     * @param string $val The string to split.
     * @return string[] The resulting array.
     */
    public static function splitLines($val)
    {
        $vals = preg_split('/\n|\r|\r\n/', $val, -1, PREG_SPLIT_NO_EMPTY);
        return array_filter(array_map('trim', $vals));
    }
}
