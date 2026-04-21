<?php
/**
 * Ticket Generator
 *
 * Builds compact PDF tickets using Storylab_PDF_Writer.
 * Up to four tickets are printed per A4 page.
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

	// Maximum tickets laid out on a single page.
	const TICKETS_PER_PAGE = 4;

	// =========================================================================
	// Public entry point
	// =========================================================================

	/**
	 * Generate one PDF file containing $quantity tickets (≤4 per page).
	 *
	 * @param array $ticket_data {
	 *     @type string show_name
	 *     @type string date         Display string, e.g. "Saturday 15 March 2025"
	 *     @type string time
	 *     @type string location
	 *     @type string order_number
	 *     @type array  ticket_names Optional array of per-ticket attendee names.
	 * }
	 * @param int   $quantity
	 * @return string|false  Absolute file path on success, false on failure.
	 */
	public static function generate( array $ticket_data, $quantity = 1 ) {
		$writer = new Storylab_PDF_Writer();

		$logo_url = get_option(
			'storylab_logo_url',
			'https://www.actorslab.co.nz/storylab/wp-content/uploads/2025/11/NEW-Story-Lab-logo.jpg'
		);

		// Build per-ticket data objects.
		$all_tickets = array();
		for ( $i = 1; $i <= $quantity; $i++ ) {
			$d             = $ticket_data;
			$d['seq']      = $i;
			$d['quantity'] = $quantity;
			$d['logo_url'] = $logo_url;
			$d['ticket_name'] = isset( $ticket_data['ticket_names'][ $i - 1 ] )
				? $ticket_data['ticket_names'][ $i - 1 ] : '';
			$all_tickets[] = $d;
		}

		// Group into pages of up to TICKETS_PER_PAGE.
		$page_groups = array_chunk( $all_tickets, self::TICKETS_PER_PAGE );

		$pages = array();
		foreach ( $page_groups as $group ) {
			$pages[] = static::make_page_closure( $group );
		}

		$pdf = $writer->generate( $pages );
		if ( empty( $pdf ) ) {
			return false;
		}

		return static::save_pdf( $pdf, $ticket_data['order_number'] );
	}

	// =========================================================================
	// Page layout
	// =========================================================================

	/**
	 * Return a closure that draws one page containing the supplied ticket group.
	 *
	 * @param array $group  Array of per-ticket data arrays (max 4).
	 * @return callable
	 */
	private static function make_page_closure( array $group ) {
		return function ( Storylab_PDF_Writer $w ) use ( $group ) {
			return Storylab_Ticket_Generator::draw_page( $w, $group );
		};
	}

	/**
	 * Draw a page containing up to four compact tickets and return the PDF
	 * operator string.
	 *
	 * @param Storylab_PDF_Writer $w
	 * @param array               $group  Array of per-ticket data arrays.
	 * @return string
	 */
	public static function draw_page( Storylab_PDF_Writer $w, array $group ) {
		$PW = Storylab_PDF_Writer::PW; // 595
		$PH = Storylab_PDF_Writer::PH; // 842

		// Page geometry.
		$mg        = 20;  // outer page margin (pts)
		$footer_h  = 16;  // red footer strip height
		$gap       = 8;   // gap between tickets

		$avail_w = $PW - 2 * $mg;                             // 555
		$avail_h = $PH - 2 * $mg - $footer_h;                 // 786
		$ticket_h = ( $avail_h - ( self::TICKETS_PER_PAGE - 1 ) * $gap ) / self::TICKETS_PER_PAGE;
		// ≈ 190.5 pts per ticket

		$ops = '';

		// White page background.
		$ops .= $w->rect( 0, 0, $PW, $PH, self::C_WHITE_R, self::C_WHITE_G, self::C_WHITE_B );

		// Red footer strip.
		$ops .= $w->rect( 0, $PH - $footer_h, $PW, $footer_h,
			self::C_RED_R, self::C_RED_G, self::C_RED_B );
		$ops .= $w->text_centered( $PW / 2, $PH - $footer_h + 11, 'actorslab.co.nz',
			7, false, self::C_WHITE_R, self::C_WHITE_G, self::C_WHITE_B );

		// Draw each ticket.
		foreach ( $group as $idx => $d ) {
			$ty   = $mg + $idx * ( $ticket_h + $gap );
			$ops .= static::draw_compact_ticket( $w, $d, $mg, $ty, $avail_w, $ticket_h );
		}

		return $ops;
	}

	// =========================================================================
	// Compact ticket
	// =========================================================================

	/**
	 * Draw a single compact ticket at the given position and return the PDF
	 * operator string.
	 *
	 * @param Storylab_PDF_Writer $w
	 * @param array  $d    Ticket data (show_name, date, time, location, ticket_name,
	 *                     order_number, seq, quantity, logo_url).
	 * @param float  $tx   Left edge (from page left).
	 * @param float  $ty   Top edge (from page top).
	 * @param float  $tw   Ticket width in pts.
	 * @param float  $th   Ticket height in pts.
	 * @return string
	 */
	public static function draw_compact_ticket( Storylab_PDF_Writer $w, array $d,
	                                            $tx, $ty, $tw, $th ) {
		$ops = '';

		$hdr_h  = 30;              // red header height
		$body_y = $ty + $hdr_h;
		$body_h = $th - $hdr_h;

		// ------------------------------------------------------------------
		// 1. Black border (full ticket area).
		// ------------------------------------------------------------------
		$ops .= $w->rect( $tx, $ty, $tw, $th,
			self::C_BLACK_R, self::C_BLACK_G, self::C_BLACK_B );

		// ------------------------------------------------------------------
		// 2. Red header bar.
		// ------------------------------------------------------------------
		$ops .= $w->rect( $tx, $ty, $tw, $hdr_h,
			self::C_RED_R, self::C_RED_G, self::C_RED_B );

		// Logo centred in header.
		$logo_url    = $d['logo_url'] ?? '';
		$logo_placed = false;
		if ( $logo_url ) {
			$logo_h    = 22;
			$logo_draw_w = min( $tw * 0.35, $logo_h * 3.5 );
			$logo_x    = $tx + ( $tw - $logo_draw_w ) / 2;
			$logo_y    = $ty + ( $hdr_h - $logo_h ) / 2;
			$img_ops   = $w->image( $logo_url, $logo_x, $logo_y, $logo_draw_w, $logo_h );
			if ( $img_ops ) {
				$ops       .= $img_ops;
				$logo_placed = true;
			}
		}
		if ( ! $logo_placed ) {
			$ops .= $w->text_centered( $tx + $tw / 2, $ty + $hdr_h / 2 + 5,
				'STORY LAB', 10, true,
				self::C_WHITE_R, self::C_WHITE_G, self::C_WHITE_B );
		}

		// ------------------------------------------------------------------
		// 3. White body.
		// ------------------------------------------------------------------
		$ops .= $w->rect( $tx + 1, $body_y, $tw - 2, $body_h - 1,
			self::C_WHITE_R, self::C_WHITE_G, self::C_WHITE_B );

		// Red left accent strip.
		$ops .= $w->rect( $tx + 1, $body_y, 5, $body_h - 1,
			self::C_RED_R, self::C_RED_G, self::C_RED_B );

		// ------------------------------------------------------------------
		// 4. Right stub area (separated by a vertical dashed line).
		// ------------------------------------------------------------------
		$stub_w = 70;
		$stub_x = $tx + $tw - $stub_w - 1;

		// Vertical dashed separator.
		$dash_y   = $body_y + 6;
		$dash_end = $body_y + $body_h - 7;
		while ( $dash_y + 2.5 <= $dash_end ) {
			$ops .= $w->rect( $stub_x, $dash_y, 0.75, 2.5,
				self::C_LGRAY_R, self::C_LGRAY_G, self::C_LGRAY_B );
			$dash_y += 5.5;
		}

		// Ticket number.
		$ticket_num = 'SL-' . str_pad( $d['order_number'], 6, '0', STR_PAD_LEFT )
		              . '-' . str_pad( $d['seq'], 2, '0', STR_PAD_LEFT );
		$stub_cx     = $stub_x + $stub_w / 2 + 2;
		$stub_label_y = $body_y + 16;

		$ops .= $w->text_centered( $stub_cx, $stub_label_y, 'TICKET', 7, false,
			self::C_DGRAY_R, self::C_DGRAY_G, self::C_DGRAY_B );
		$stub_label_y += 10;
		$ops .= $w->text_centered( $stub_cx, $stub_label_y, $ticket_num, 7, true,
			self::C_BLACK_R, self::C_BLACK_G, self::C_BLACK_B );
		$stub_label_y += 24;

		$ops .= $w->text_centered( $stub_cx, $stub_label_y, 'TICKET', 7, false,
			self::C_DGRAY_R, self::C_DGRAY_G, self::C_DGRAY_B );
		$stub_label_y += 10;
		$ops .= $w->text_centered( $stub_cx, $stub_label_y,
			$d['seq'] . ' of ' . $d['quantity'], 9, true,
			self::C_BLACK_R, self::C_BLACK_G, self::C_BLACK_B );

		// ------------------------------------------------------------------
		// 5. Main content area.
		// ------------------------------------------------------------------
		$content_x = $tx + 13;
		$content_w = $stub_x - $content_x - 6;
		$cursor_y  = $body_y + 16;

		// Show name.
		$show_name = ! empty( $d['show_name'] ) ? $d['show_name'] : 'Story Lab';
		$name_size = 12;
		while ( $name_size > 8
		        && $w->approx_text_width( $show_name, $name_size, true ) > $content_w ) {
			$name_size--;
		}
		$result    = $w->text_wrap( $content_x, $cursor_y, $show_name, $content_w,
			$name_size, true,
			self::C_RED_R, self::C_RED_G, self::C_RED_B );
		$ops      .= $result['ops'];
		$cursor_y  = $result['y'] + 3;

		// Thin red separator line.
		$ops     .= $w->hline( $content_x, $cursor_y, $content_w - 4,
			self::C_RED_R, self::C_RED_G, self::C_RED_B, 0.75 );
		$cursor_y += 8;

		// Detail rows: DATE, TIME, VENUE, NAME.
		$label_size = 7;
		$value_size = 9;
		$row_h      = 13;

		$details = array();
		if ( ! empty( $d['date'] ) )        { $details[] = array( 'DATE',  $d['date'] ); }
		if ( ! empty( $d['time'] ) )        { $details[] = array( 'TIME',  $d['time'] ); }
		if ( ! empty( $d['location'] ) )    { $details[] = array( 'VENUE', $d['location'] ); }
		if ( ! empty( $d['ticket_name'] ) ) { $details[] = array( 'NAME',  $d['ticket_name'] ); }

		foreach ( $details as $row ) {
			list( $label, $value ) = $row;
			$lw = $w->approx_text_width( $label . '  ', $label_size, false );
			$ops .= $w->text( $content_x, $cursor_y, $label, $label_size, false,
				self::C_DGRAY_R, self::C_DGRAY_G, self::C_DGRAY_B );
			$val_result = $w->text_wrap(
				$content_x + $lw, $cursor_y,
				$value, $content_w - $lw,
				$value_size, true,
				self::C_BLACK_R, self::C_BLACK_G, self::C_BLACK_B,
				$row_h
			);
			$ops      .= $val_result['ops'];
			$cursor_y  = max( $cursor_y + $row_h, $val_result['y'] );
		}

		// ------------------------------------------------------------------
		// 6. Tear-here line near bottom of ticket.
		// ------------------------------------------------------------------
		$tear_y = $ty + $th - 26;
		$ops   .= $w->hline( $tx + 6, $tear_y, $stub_x - $tx - 6,
			self::C_LGRAY_R, self::C_LGRAY_G, self::C_LGRAY_B, 0.5 );
		$ops   .= $w->text_centered(
			$tx + ( $stub_x - $tx ) / 2,
			$tear_y + 8,
			'- - - - - - - - TEAR HERE - - - - - - - -',
			6, false,
			self::C_LGRAY_R, self::C_LGRAY_G, self::C_LGRAY_B
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
