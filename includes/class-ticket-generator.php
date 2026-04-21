<?php
/**
 * Ticket Generator
 *
 * Builds the visual layout of a PDF ticket using Storylab_PDF_Writer
 * and saves the resulting file to the uploads directory.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Storylab_Ticket_Generator {

	// =========================================================================
	// Colour palette  (RGB 0-255)
	// =========================================================================
	const C_RED_R   = 205;
	const C_RED_G   =  18;
	const C_RED_B   =  20;   // #CD1214

	const C_BLACK_R =   0;
	const C_BLACK_G =   0;
	const C_BLACK_B =   0;

	const C_WHITE_R = 255;
	const C_WHITE_G = 255;
	const C_WHITE_B = 255;

	const C_LGRAY_R = 180;
	const C_LGRAY_G = 180;
	const C_LGRAY_B = 180;

	const C_DGRAY_R =  80;
	const C_DGRAY_G =  80;
	const C_DGRAY_B =  80;

	// =========================================================================

	/**
	 * Generate one PDF file containing $quantity pages, one ticket per page.
	 *
	 * @param array $ticket_data {
	 *     @type string show_name
	 *     @type string date         (display string, e.g. "Saturday 15 March 2025")
	 *     @type string time
	 *     @type string location
	 *     @type string order_number
	 *     @type int    ticket_index  1-based index within the order
	 *     @type int    total_tickets Total tickets in the order
	 * }
	 * @param int   $quantity   Number of identical tickets to put in the PDF.
	 * @return string|false  Absolute file path on success, false on failure.
	 */
	public static function generate( array $ticket_data, $quantity = 1 ) {
		$writer = new Storylab_PDF_Writer();

		$logo_url = get_option( 'storylab_logo_url',
			'https://www.actorslab.co.nz/storylab/wp-content/uploads/2025/11/NEW-Story-Lab-logo.jpg'
		);

		$pages = [];
		for ( $i = 1; $i <= $quantity; $i++ ) {
			$data             = $ticket_data;
			$data['seq']      = $i;
			$data['quantity'] = $quantity;
			$data['logo_url'] = $logo_url;

			$pages[] = static::make_page_closure( $data );
		}

		$pdf = $writer->generate( $pages );
		if ( empty( $pdf ) ) {
			return false;
		}

		// Write to uploads.
		$path = static::save_pdf( $pdf, $ticket_data['order_number'] );
		return $path;
	}

	/**
	 * Return a closure that draws one ticket page.
	 */
	private static function make_page_closure( array $d ) {
		return function ( Storylab_PDF_Writer $w ) use ( $d ) {
			return Storylab_Ticket_Generator::draw_page( $w, $d );
		};
	}

	// =========================================================================
	// Visual layout
	// =========================================================================

	/**
	 * Draw a single ticket page and return the PDF operator string.
	 */
	public static function draw_page( Storylab_PDF_Writer $w, array $d ) {
		$PW = Storylab_PDF_Writer::PW; // 595
		$PH = Storylab_PDF_Writer::PH; // 842

		$ops = '';

		// ------------------------------------------------------------------
		// 1. Full-page white background (so non-printed areas are white).
		// ------------------------------------------------------------------
		$ops .= $w->rect( 0, 0, $PW, $PH, self::C_WHITE_R, self::C_WHITE_G, self::C_WHITE_B );

		// ------------------------------------------------------------------
		// 2. Black ticket body background (with 28pt margins, page-centred).
		// ------------------------------------------------------------------
		$mg  = 28;               // outer margin
		$tw  = $PW - 2 * $mg;   // ticket width   = 539
		$th  = $PH - 2 * $mg;   // ticket height  = 786
		$ops .= $w->rect( $mg, $mg, $tw, $th, self::C_BLACK_R, self::C_BLACK_G, self::C_BLACK_B );

		// ------------------------------------------------------------------
		// 3. Red header bar inside the ticket.
		// ------------------------------------------------------------------
		$hdr_h = 145;            // header height (pts)
		$ops  .= $w->rect( $mg, $mg, $tw, $hdr_h, self::C_RED_R, self::C_RED_G, self::C_RED_B );

		// ------------------------------------------------------------------
		// 4. Logo centred inside the header.
		// ------------------------------------------------------------------
		$logo_url  = $d['logo_url'] ?? '';
		$logo_draw_h = 100;
		$logo_draw_w = 0; // auto
		$logo_placed = false;

		if ( $logo_url ) {
			// Try to figure out natural dimensions for accurate centering.
			// We'll let PDF writer calculate width from height.
			// First, find natural aspect ratio via cache (the writer caches it).
			// Just let image() handle it; but we need the width to centre it.
			// Use a helper approach: draw at max width = tw - 40, max height = logo_draw_h.
			$max_logo_w = $tw - 40;
			// We place it at a tentative X; the writer will use draw_h and auto-calc width.
			// For centering we estimate: typical logo aspect ratio ~3:1.
			$est_logo_w = min( $max_logo_w, $logo_draw_h * 3.0 );
			$logo_x     = $mg + ( $tw - $est_logo_w ) / 2;
			$logo_y     = $mg + ( $hdr_h - $logo_draw_h ) / 2;

			$img_ops = $w->image( $logo_url, $logo_x, $logo_y, $est_logo_w, $logo_draw_h );
			if ( $img_ops ) {
				$ops       .= $img_ops;
				$logo_placed = true;
			}
		}

		if ( ! $logo_placed ) {
			// Fallback: large text "STORY LAB" centred in header.
			$ops .= $w->text_centered(
				$mg + $tw / 2,
				$mg + $hdr_h / 2 + 10,
				'STORY LAB',
				32, true,
				self::C_WHITE_R, self::C_WHITE_G, self::C_WHITE_B
			);
		}

		// ------------------------------------------------------------------
		// 5. White content card inside the black ticket area.
		// ------------------------------------------------------------------
		$card_mg = 14;             // padding between outer ticket edge and card
		$card_x  = $mg + $card_mg;
		$card_y  = $mg + $hdr_h + 12;
		$card_w  = $tw - 2 * $card_mg;
		$card_h  = $th - $hdr_h - $card_mg - 12;
		$ops    .= $w->rect( $card_x, $card_y, $card_w, $card_h,
			self::C_WHITE_R, self::C_WHITE_G, self::C_WHITE_B );

		// Red left-side accent strip on the card.
		$ops .= $w->rect( $card_x, $card_y, 6, $card_h,
			self::C_RED_R, self::C_RED_G, self::C_RED_B );

		// ------------------------------------------------------------------
		// 6. Show name (large, red text on white card).
		// ------------------------------------------------------------------
		$content_x = $card_x + 22;       // indent past red strip
		$content_w = $card_w - 30;       // usable text width
		$cursor_y  = $card_y + 42;       // first baseline

		$show_name = ! empty( $d['show_name'] ) ? $d['show_name'] : 'Story Lab';

		// Scale down font size if name is very long.
		$name_size = 26;
		while ( $name_size > 14 && $w->approx_text_width( $show_name, $name_size, true ) > $content_w ) {
			$name_size -= 2;
		}

		$result    = $w->text_wrap( $content_x, $cursor_y, $show_name, $content_w,
			$name_size, true, self::C_RED_R, self::C_RED_G, self::C_RED_B );
		$ops      .= $result['ops'];
		$cursor_y  = $result['y'] + 6;

		// Red separator.
		$ops      .= $w->hline( $content_x, $cursor_y, $content_w - 4,
			self::C_RED_R, self::C_RED_G, self::C_RED_B, 1.5 );
		$cursor_y += 18;

		// ------------------------------------------------------------------
		// 7. Date / Time / Venue detail rows.
		// ------------------------------------------------------------------
		$label_size = 9;
		$value_size = 13;
		$row_gap    = 6;

		$details = [];
		if ( ! empty( $d['date'] ) ) {
			$details[] = [ 'DATE',  $d['date'] ];
		}
		if ( ! empty( $d['time'] ) ) {
			$details[] = [ 'TIME',  $d['time'] ];
		}
		if ( ! empty( $d['location'] ) ) {
			$details[] = [ 'VENUE', $d['location'] ];
		}

		foreach ( $details as $row ) {
			list( $label, $value ) = $row;

			// Label (small, grey).
			$ops      .= $w->text( $content_x, $cursor_y, $label,
				$label_size, false,
				self::C_DGRAY_R, self::C_DGRAY_G, self::C_DGRAY_B );
			$cursor_y += $label_size + 2;

			// Value (may wrap).
			$result    = $w->text_wrap( $content_x, $cursor_y, $value, $content_w,
				$value_size, true,
				self::C_BLACK_R, self::C_BLACK_G, self::C_BLACK_B, $value_size * 1.35 );
			$ops      .= $result['ops'];
			$cursor_y  = $result['y'] + $row_gap + 2;
		}

		// ------------------------------------------------------------------
		// 8. Tear / stub separator near the bottom of the card.
		// ------------------------------------------------------------------
		$stub_h    = 60;
		$stub_y    = $card_y + $card_h - $stub_h;   // top of stub area
		$ops      .= $w->hline( $card_x + 6, $stub_y, $card_w - 12,
			self::C_LGRAY_R, self::C_LGRAY_G, self::C_LGRAY_B, 0.5 );

		// Dashed "tear here" label.
		$ops .= $w->text_centered(
			$card_x + $card_w / 2,
			$stub_y + 9,
			'- - - - - - - - - - - - - - TEAR HERE - - - - - - - - - - - - - -',
			7, false,
			self::C_LGRAY_R, self::C_LGRAY_G, self::C_LGRAY_B
		);

		// ------------------------------------------------------------------
		// 9. Ticket stub info.
		// ------------------------------------------------------------------
		$stub_label_y = $stub_y + 20;

		// Ticket number.
		$ticket_num = 'SL-' . str_pad( $d['order_number'], 6, '0', STR_PAD_LEFT )
		              . '-' . str_pad( $d['seq'], 2, '0', STR_PAD_LEFT );
		$ops .= $w->text( $content_x, $stub_label_y, 'TICKET', $label_size, false,
			self::C_DGRAY_R, self::C_DGRAY_G, self::C_DGRAY_B );
		$ops .= $w->text( $content_x, $stub_label_y + $label_size + 3, $ticket_num,
			12, true, self::C_BLACK_R, self::C_BLACK_G, self::C_BLACK_B );

		// Quantity indicator (right side of stub).
		$qty_label = $d['seq'] . ' of ' . $d['quantity'];
		$qty_x     = $card_x + $card_w - 70;
		$ops .= $w->text( $qty_x, $stub_label_y, 'TICKET', $label_size, false,
			self::C_DGRAY_R, self::C_DGRAY_G, self::C_DGRAY_B );
		$ops .= $w->text( $qty_x, $stub_label_y + $label_size + 3, $qty_label,
			12, true, self::C_BLACK_R, self::C_BLACK_G, self::C_BLACK_B );

		// ------------------------------------------------------------------
		// 10. Red footer bar at the bottom of the page.
		// ------------------------------------------------------------------
		$footer_h = $mg;
		$ops .= $w->rect( 0, $PH - $footer_h, $PW, $footer_h,
			self::C_RED_R, self::C_RED_G, self::C_RED_B );
		$ops .= $w->text_centered(
			$PW / 2,
			$PH - $footer_h + 16,
			'actorslab.co.nz',
			9, false,
			self::C_WHITE_R, self::C_WHITE_G, self::C_WHITE_B
		);

		return $ops;
	}

	// =========================================================================
	// File helpers
	// =========================================================================

	/**
	 * Save PDF bytes to the uploads/storylab-tickets directory.
	 *
	 * @param string $pdf_bytes
	 * @param string $order_number
	 * @return string|false Absolute path on success.
	 */
	private static function save_pdf( $pdf_bytes, $order_number ) {
		$upload = wp_upload_dir();
		$dir    = trailingslashit( $upload['basedir'] ) . 'storylab-tickets';

		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
			file_put_contents( $dir . '/index.html', '' ); // phpcs:ignore
		}

		$filename = 'ticket-order-' . sanitize_file_name( $order_number ) . '-' . time() . '.pdf';
		$path     = $dir . '/' . $filename;

		$written = file_put_contents( $path, $pdf_bytes ); // phpcs:ignore
		return $written !== false ? $path : false;
	}
}
