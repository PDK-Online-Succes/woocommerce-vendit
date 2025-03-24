<?php
function sanitize_slug($string) {
	// Convert to ASCII
	$string = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $string);
	// Remove any remaining non-ASCII characters
	$string = preg_replace('/[^\x20-\x7E]/', '', $string);
	// Optionally replace special characters with underscores
	$string = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $string);
	//convert to UTF-8
	$string = iconv('ASCII', 'UTF-8//TRANSLIT//IGNORE', $string);
	// Replace multiple underscores with a single underscore
	$string = preg_replace('/_+/', '_', $string);
	return $string;
}

function sanitize_text($text) {
    return trim(htmlspecialchars($text, ENT_QUOTES, 'UTF-8')); // Safe alternative
}

function sanitize_html($input) {
    $allowed_tags = [
        'a' => ['href', 'title', 'target', 'rel', 'style'],
        'b' => [''],
        'i' => [''],
        'u' => [''],
        'strong' => [''],
        'em' => [''],
        'p' => [''],
        'ul' => [''],
        'ol' => [''],
        'li' => [''],
        'br' => [],
        'span' => ['style'],
        'div' => ['style'],
        'img' => ['src', 'alt', 'title', 'width', 'height', 'style'],
        'h1' => ['style'], 'h2' => ['style'], 'h3' => ['style'], 'h4' => ['style'], 'h5' => ['style'], 'h6' => ['style']
    ];

    $input_wrapped = '<div>' . mb_convert_encoding($input, 'HTML-ENTITIES', 'UTF-8') . '</div>';

    $doc = new \DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML($input_wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    $xpath = new \DOMXPath($doc);
    $nodes = $xpath->query('//*');

    foreach ($nodes as $el) {
        if (!$el instanceof \DOMElement) continue;

        $tag = $el->tagName;

        // 1. Check and clean style
		if ($el->hasAttribute('style')) {
			$style = $el->getAttribute('style');

			$hasBold = stripos($style, 'font-weight:bold') !== false;

			// Remove background-color, font-size, color, font-weight
			$sanitized = preg_replace([
				'/background-color\s*:\s*[^;]+;?/i',
				'/font-size\s*:\s*[^;]+;?/i',
				'/color\s*:\s*[^;]+;?/i',
				'/font-weight\s*:\s*bold;?/i'
			], '', $style);

			$sanitized = trim($sanitized, " ;");

			if ($hasBold) {
				// Wrap the element's content in <strong>
				$strong = $doc->createElement('strong');
				while ($el->firstChild) {
					$strong->appendChild($el->firstChild);
				}
				$el->appendChild($strong);
			}

			if ($sanitized === '') {
				$el->removeAttribute('style');
			} else {
				$el->setAttribute('style', $sanitized);
			}
		}

        // 2. Remove <p> and <span> inside <li> by unwrapping them
        if (in_array($tag, ['p', 'span']) && $el->parentNode && $el->parentNode->nodeName === 'li') {
            while ($el->firstChild) {
                $el->parentNode->insertBefore($el->firstChild, $el);
            }
            $el->parentNode->removeChild($el);
            continue;
        }

        // 3. Remove disallowed tags
        if (!array_key_exists($tag, $allowed_tags)) {
            $el->parentNode->removeChild($el);
            continue;
        }

        // 4. Clean attributes
        foreach (iterator_to_array($el->attributes) as $attr) {
            $name = $attr->nodeName;
            $value = $attr->nodeValue;

            if (!in_array($name, $allowed_tags[$tag])) {
                $el->removeAttribute($name);
                continue;
			}
        }
    }

    $wrapper = $doc->getElementsByTagName('div')->item(0);
    if (!$wrapper) return '';

    $output = '';
    foreach ($wrapper->childNodes as $child) {
        $output .= $doc->saveHTML($child);
    }

    return $output;
}