<?php
/**
 * Minimal PDF Writer
 *
 * Generates a valid PDF 1.4 document supporting:
 *  - Filled colour rectangles
 *  - Text using standard Type1 fonts (Helvetica / Helvetica-Bold)
 *  - Embedded JPEG images (fetched from URL or local path)
 *
 * No external libraries required.
 *
 * Coordinates: this class works in a top-down system (y=0 at top of page)
 * and converts internally to the PDF bottom-up system.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Storylab_PDF_Writer {

	// A4 page size in points (72 pt = 1 inch; A4 = 210 × 297 mm)
	const PW = 595;
	const PH = 842;

	/** @var string Raw PDF byte buffer */
	private $buf = '';

	/** @var array Map of object ID → byte offset */
	private $offsets = [];

	/** @var int Highest object ID assigned so far */
	private $next_id = 0;

	/** @var array Embedded images: url/path → ['id','w','h'] */
	private $img_cache = [];

	/** @var int Object ID of Helvetica font */
	private $font_regular = 0;

	/** @var int Object ID of Helvetica-Bold font */
	private $font_bold = 0;

	// =========================================================================
	// Public API
	// =========================================================================

	/**
	 * Generate a PDF with one page per element in $pages.
	 *
	 * Each element in $pages must be a callable:
	 *   function( Storylab_PDF_Writer $w ) : string
	 * that returns a PDF content-stream string built with the helpers below.
	 *
	 * @param callable[] $pages
	 * @return string  Binary PDF content.
	 */
	public function generate( array $pages ) {
		$this->reset();
		$this->buf = "%PDF-1.4\n%\xe2\xe3\xcf\xd3\n";

		// Objects 1 (Catalog) and 2 (Pages) are written last; reserve IDs.
		$this->next_id = 2;

		// Font objects.
		$this->font_regular = $this->new_obj(
			'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>'
		);
		$this->font_bold = $this->new_obj(
			'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>'
		);

		$page_ids = [];

		foreach ( $pages as $page_fn ) {
			// Let the callable build the content stream.
			$content = call_user_func( $page_fn, $this );

			// Collect unique image XObjects used on this page.
			$xobj_dict = '';
			foreach ( $this->img_cache as $img ) {
				$xobj_dict .= " /Im{$img['id']} {$img['id']} 0 R";
			}
			$resources = "<< /Font << /F1 {$this->font_regular} 0 R /F2 {$this->font_bold} 0 R >>"
			             . ( $xobj_dict ? " /XObject <<$xobj_dict >>" : '' )
			             . ' >>';

			// Content stream object.
			$len        = strlen( $content );
			$stream_id  = $this->new_stream( "/Length $len", $content );

			// Page object — references Pages (obj 2).
			$page_ids[] = $this->new_obj(
				'<< /Type /Page /Parent 2 0 R'
				. ' /MediaBox [0 0 ' . self::PW . ' ' . self::PH . ']'
				. " /Contents $stream_id 0 R"
				. " /Resources $resources >>"
			);
		}

		// Pages object (ID 2).
		$kids  = implode( ' 0 R ', $page_ids ) . ' 0 R';
		$count = count( $page_ids );
		$this->offsets[2] = strlen( $this->buf );
		$this->buf .= "2 0 obj\n<< /Type /Pages /Kids [$kids] /Count $count >>\nendobj\n";

		// Catalog object (ID 1).
		$this->offsets[1] = strlen( $this->buf );
		$this->buf .= "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";

		// Cross-reference table.
		$xref_offset = strlen( $this->buf );
		$total       = $this->next_id + 1; // obj 0 … next_id
		$this->buf  .= "xref\n0 $total\n";
		$this->buf  .= "0000000000 65535 f \n";
		for ( $i = 1; $i <= $this->next_id; $i++ ) {
			$off = isset( $this->offsets[ $i ] ) ? $this->offsets[ $i ] : 0;
			$this->buf .= sprintf( "%010d 00000 n \n", $off );
		}

		// Trailer.
		$this->buf .= "trailer\n<< /Size $total /Root 1 0 R >>\nstartxref\n$xref_offset\n%%EOF";

		return $this->buf;
	}

	// =========================================================================
	// Content-stream helpers (return PDF operator strings)
	// =========================================================================

	/**
	 * Filled rectangle.
	 *
	 * @param float $x     Left edge (pt, from left).
	 * @param float $y     Top edge  (pt, from top of page).
	 * @param float $w     Width in points.
	 * @param float $h     Height in points.
	 * @param int   $r,g,b Fill colour (0-255 each).
	 */
	public function rect( $x, $y, $w, $h, $r, $g, $b ) {
		$pdf_y = self::PH - $y - $h;
		$rf    = $this->cc( $r );
		$gf    = $this->cc( $g );
		$bf    = $this->cc( $b );
		return "q\n$rf $gf $bf rg\n$x $pdf_y $w $h re f\nQ\n";
	}

	/**
	 * Stroked (outline) rectangle.
	 */
	public function rect_stroke( $x, $y, $w, $h, $r, $g, $b, $lw = 1 ) {
		$pdf_y = self::PH - $y - $h;
		$rf    = $this->cc( $r );
		$gf    = $this->cc( $g );
		$bf    = $this->cc( $b );
		return "q\n$lw w\n$rf $gf $bf RG\n$x $pdf_y $w $h re S\nQ\n";
	}

	/**
	 * Horizontal rule.
	 */
	public function hline( $x, $y, $w, $r, $g, $b, $lw = 0.5 ) {
		return $this->rect( $x, $y, $w, $lw, $r, $g, $b );
	}

	/**
	 * Single-line text.
	 *
	 * @param float  $x        X position (from left).
	 * @param float  $y        Y position of text BASELINE (from top of page).
	 * @param string $text     The text (UTF-8 → will be converted to Latin-1).
	 * @param int    $size     Font size in points.
	 * @param bool   $bold     Use bold variant.
	 * @param int    $r,g,b   Text colour (0-255).
	 */
	public function text( $x, $y, $text, $size, $bold, $r, $g, $b ) {
		$pdf_y = self::PH - $y;
		$font  = $bold ? 'F2' : 'F1';
		$esc   = $this->pdf_str( $text );
		$rf    = $this->cc( $r );
		$gf    = $this->cc( $g );
		$bf    = $this->cc( $b );
		return "q\nBT\n/$font $size Tf\n$rf $gf $bf rg\n$x $pdf_y Td\n($esc) Tj\nET\nQ\n";
	}

	/**
	 * Centred single-line text.
	 *
	 * @param float  $cx   Centre X of the available width.
	 * @param float  $y    Baseline Y from top of page.
	 * @param string $text
	 * @param int    $size
	 * @param bool   $bold
	 * @param int    $r,g,b
	 */
	public function text_centered( $cx, $y, $text, $size, $bold, $r, $g, $b ) {
		$tw = $this->approx_text_width( $text, $size, $bold );
		$x  = $cx - $tw / 2;
		return $this->text( $x, $y, $text, $size, $bold, $r, $g, $b );
	}

	/**
	 * Multi-line text block.  Returns content string and the new Y position.
	 *
	 * @param float  $x
	 * @param float  $y         Baseline of first line (from top).
	 * @param string $text
	 * @param float  $max_w     Wrap width in points.
	 * @param int    $size
	 * @param bool   $bold
	 * @param int    $r,g,b
	 * @param float  $leading   Line height in points (defaults to size × 1.4).
	 * @return array [ 'ops' => string, 'y' => float ]
	 */
	public function text_wrap( $x, $y, $text, $max_w, $size, $bold, $r, $g, $b, $leading = 0 ) {
		if ( ! $leading ) {
			$leading = $size * 1.4;
		}
		$lines = $this->word_wrap( $text, $size, $bold, $max_w );
		$ops   = '';
		foreach ( $lines as $line ) {
			$ops .= $this->text( $x, $y, $line, $size, $bold, $r, $g, $b );
			$y   += $leading;
		}
		return [ 'ops' => $ops, 'y' => $y ];
	}

	/**
	 * Embed and draw an image.
	 *
	 * @param string $src   URL or absolute file path to a JPEG / PNG image.
	 * @param float  $x     Left edge.
	 * @param float  $y     Top edge (from top of page).
	 * @param float  $draw_w  Desired width in points (height auto-scaled).
	 * @param float  $draw_h  Desired height (0 = auto from draw_w).
	 * @return string  PDF operator string (empty on failure).
	 */
	public function image( $src, $x, $y, $draw_w, $draw_h = 0 ) {
		$img = $this->embed_image( $src );
		if ( ! $img ) {
			return '';
		}
		if ( ! $draw_h ) {
			$draw_h = $draw_w * ( $img['h'] / $img['w'] );
		}
		$pdf_y = self::PH - $y - $draw_h;
		$name  = "/Im{$img['id']}";
		return "q\n$draw_w 0 0 $draw_h $x $pdf_y cm\n$name Do\nQ\n";
	}

	// =========================================================================
	// Private internals
	// =========================================================================

	private function reset() {
		$this->buf      = '';
		$this->offsets  = [];
		$this->next_id  = 0;
		$this->img_cache = [];
	}

	/** Write a regular object and return its ID. */
	private function new_obj( $content ) {
		$id = ++$this->next_id;
		$this->offsets[ $id ] = strlen( $this->buf );
		$this->buf .= "$id 0 obj\n$content\nendobj\n";
		return $id;
	}

	/** Write a stream object and return its ID. */
	private function new_stream( $dict_extra, $data ) {
		$id  = ++$this->next_id;
		$len = strlen( $data );
		$this->offsets[ $id ] = strlen( $this->buf );
		$this->buf .= "$id 0 obj\n<< /Length $len $dict_extra>>\nstream\n$data\nendstream\nendobj\n";
		return $id;
	}

	/**
	 * Embed a JPEG or PNG image from a URL or file path.
	 * Returns array ['id','w','h'] or null on failure.
	 */
	private function embed_image( $src ) {
		if ( isset( $this->img_cache[ $src ] ) ) {
			return $this->img_cache[ $src ];
		}

		// Fetch raw bytes.
		if ( filter_var( $src, FILTER_VALIDATE_URL ) ) {
			$resp = wp_remote_get( $src, [ 'timeout' => 20 ] );
			if ( is_wp_error( $resp ) ) {
				return null;
			}
			$data = wp_remote_retrieve_body( $resp );
		} else {
			if ( ! is_readable( $src ) ) {
				return null;
			}
			$data = file_get_contents( $src ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		if ( empty( $data ) ) {
			return null;
		}

		$info = @getimagesizefromstring( $data );
		if ( ! $info ) {
			return null;
		}

		$w    = $info[0];
		$h    = $info[1];
		$mime = $info['mime'];

		// Convert non-JPEG to JPEG via GD if available.
		if ( 'image/jpeg' !== $mime && 'image/jpg' !== $mime ) {
			if ( function_exists( 'imagecreatefromstring' ) && function_exists( 'imagejpeg' ) ) {
				$gd = @imagecreatefromstring( $data );
				if ( $gd ) {
					ob_start();
					imagejpeg( $gd, null, 92 );
					$data = ob_get_clean();
					imagedestroy( $gd );
					$info2 = @getimagesizefromstring( $data );
					if ( $info2 ) {
						$w    = $info2[0];
						$h    = $info2[1];
						$mime = 'image/jpeg';
					}
				}
			}
			// If still not JPEG, give up.
			if ( 'image/jpeg' !== $mime && 'image/jpg' !== $mime ) {
				return null;
			}
		}

		// Write the image XObject.
		$id  = ++$this->next_id;
		$len = strlen( $data );
		$this->offsets[ $id ] = strlen( $this->buf );
		$this->buf .= "$id 0 obj\n"
		              . "<< /Type /XObject /Subtype /Image /Width $w /Height $h"
		              . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode'
		              . " /Length $len >>\n"
		              . "stream\n$data\nendstream\nendobj\n";

		$result = [ 'id' => $id, 'w' => $w, 'h' => $h ];
		$this->img_cache[ $src ] = $result;
		return $result;
	}

	/**
	 * Escape a UTF-8 string for use inside a PDF literal string ().
	 * Converts to Latin-1 (WinAnsiEncoding); unmappable chars → '?'.
	 */
	private function pdf_str( $text ) {
		// Convert UTF-8 → Windows-1252 (a superset of Latin-1 used by WinAnsiEncoding).
		if ( function_exists( 'mb_convert_encoding' ) ) {
			$text = mb_convert_encoding( $text, 'Windows-1252', 'UTF-8' );
		}
		$text = str_replace( '\\', '\\\\', $text );
		$text = str_replace( '(', '\\(', $text );
		$text = str_replace( ')', '\\)', $text );
		return $text;
	}

	/** Convert 0-255 colour channel to 0.000-1.000 PDF decimal. */
	private function cc( $v ) {
		return number_format( $v / 255, 4, '.', '' );
	}

	/**
	 * Approximate text width for font-size scaling.
	 * Based on Helvetica average glyph widths.
	 */
	public function approx_text_width( $text, $size, $bold = false ) {
		// Per-character average advance widths (in 1/1000 em units) for Helvetica.
		// This is a rough average; good enough for layout purposes.
		$avg = $bold ? 580 : 556;
		return strlen( $text ) * $avg / 1000 * $size;
	}

	/**
	 * Word-wrap $text to fit within $max_w points at the given font size.
	 */
	private function word_wrap( $text, $size, $bold, $max_w ) {
		$words  = preg_split( '/\s+/', trim( $text ) );
		$lines  = [];
		$cur    = '';

		foreach ( $words as $word ) {
			$test = $cur === '' ? $word : "$cur $word";
			if ( $this->approx_text_width( $test, $size, $bold ) <= $max_w ) {
				$cur = $test;
			} else {
				if ( $cur !== '' ) {
					$lines[] = $cur;
				}
				$cur = $word;
			}
		}
		if ( $cur !== '' ) {
			$lines[] = $cur;
		}
		return $lines ?: [ '' ];
	}
}
