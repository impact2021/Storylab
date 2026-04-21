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

	// Cream/ivory background
	const C_CREAM_R = 245;
	const C_CREAM_G = 240;
	const C_CREAM_B = 225;

	// Gold/tan border accent
	const C_GOLD_R  = 184;
	const C_GOLD_G  = 163;
	const C_GOLD_B  =  72;

	// Dark maroon bottom accent
	const C_DKRED_R = 100;
	const C_DKRED_G =  20;
	const C_DKRED_B =  25;

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
		$mg       = 20; // outer page margin (pts)
		$gap      = 12; // gap between tickets
		$footer_h = 14; // small footer strip height

		$avail_w  = $PW - 2 * $mg;                            // 555
		$avail_h  = $PH - 2 * $mg - $footer_h;                // 794
		$ticket_h = ( $avail_h - ( self::TICKETS_PER_PAGE - 1 ) * $gap ) / self::TICKETS_PER_PAGE;
		// ≈ 191 pts per ticket

		$ops = '';

		// Light gray page background.
		$ops .= $w->rect( 0, 0, $PW, $PH, 230, 230, 230 );

		// Subtle footer strip.
		$ops .= $w->rect( 0, $PH - $footer_h, $PW, $footer_h,
			self::C_DGRAY_R, self::C_DGRAY_G, self::C_DGRAY_B );
		$ops .= $w->text_centered( $PW / 2, $PH - $footer_h + 9, 'actorslab.co.nz',
			6, false, self::C_LGRAY_R, self::C_LGRAY_G, self::C_LGRAY_B );

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

		// ------------------------------------------------------------------
		// Layout geometry
		// ------------------------------------------------------------------
		$left_blk_w   = 8;   // black bar on far left
		$left_red_w   = 12;  // red bar next to the black bar
		$left_bar_w   = $left_blk_w + $left_red_w; // 20 pts total left bars

		$stub_w       = 114; // right stub width
		$stub_x       = $tx + $tw - $stub_w; // left edge of stub

		$logo_zone_w  = 84;  // logo column width
		$logo_zone_x  = $tx + $left_bar_w; // = tx + 20

		$vsep_x       = $logo_zone_x + $logo_zone_w; // thin vertical separator x
		$content_x    = $vsep_x + 3;                 // content text starts here
		$content_w    = $stub_x - $content_x - 6;    // available text width

		$btm_h        = 18;  // dark maroon bottom accent height

		// ------------------------------------------------------------------
		// 1. Cream background (full ticket area).
		// ------------------------------------------------------------------
		$ops .= $w->rect( $tx, $ty, $tw, $th,
			self::C_CREAM_R, self::C_CREAM_G, self::C_CREAM_B );

		// ------------------------------------------------------------------
		// 2. Gold outer border (top, right, bottom — not left because of bars).
		// ------------------------------------------------------------------
		$border_start = $tx + $left_bar_w;
		$border_w     = $tw - $left_bar_w;
		// Outer lines.
		$ops .= $w->hline( $border_start, $ty, $border_w,
			self::C_GOLD_R, self::C_GOLD_G, self::C_GOLD_B, 1.5 );
		$ops .= $w->hline( $border_start, $ty + $th - 1.5, $border_w,
			self::C_GOLD_R, self::C_GOLD_G, self::C_GOLD_B, 1.5 );
		$ops .= $w->rect( $tx + $tw - 1.5, $ty, 1.5, $th,
			self::C_GOLD_R, self::C_GOLD_G, self::C_GOLD_B );
		// Inner lines (thinner, inset 3 pts).
		$ops .= $w->hline( $border_start + 3, $ty + 4, $border_w - 5,
			self::C_GOLD_R, self::C_GOLD_G, self::C_GOLD_B, 0.5 );
		$ops .= $w->hline( $border_start + 3, $ty + $th - 5, $border_w - 5,
			self::C_GOLD_R, self::C_GOLD_G, self::C_GOLD_B, 0.5 );

		// ------------------------------------------------------------------
		// 3. Left decorative bars (full ticket height).
		// ------------------------------------------------------------------
		$ops .= $w->rect( $tx, $ty, $left_blk_w, $th,
			self::C_BLACK_R, self::C_BLACK_G, self::C_BLACK_B );
		$ops .= $w->rect( $tx + $left_blk_w, $ty, $left_red_w, $th,
			self::C_RED_R, self::C_RED_G, self::C_RED_B );
		// Dark maroon accent at the bottom of the left bars.
		$ops .= $w->rect( $tx, $ty + $th - $btm_h, $left_bar_w + 2, $btm_h,
			self::C_DKRED_R, self::C_DKRED_G, self::C_DKRED_B );

		// ------------------------------------------------------------------
		// 4. Thin red vertical separator between logo zone and content.
		// ------------------------------------------------------------------
		$ops .= $w->rect( $vsep_x, $ty + 6, 1, $th - 12,
			self::C_RED_R, self::C_RED_G, self::C_RED_B );

		// ------------------------------------------------------------------
		// 5. Logo centred in the logo zone.
		// ------------------------------------------------------------------
		$logo_url    = $d['logo_url'] ?? '';
		$logo_placed = false;
		if ( $logo_url ) {
			$logo_draw_h = min( 55, $th * 0.38 );
			$logo_draw_w = $logo_draw_h * 1.25;
			if ( $logo_draw_w > $logo_zone_w - 10 ) {
				$logo_draw_w = $logo_zone_w - 10;
				$logo_draw_h = $logo_draw_w / 1.25;
			}
			$lx      = $logo_zone_x + ( $logo_zone_w - $logo_draw_w ) / 2;
			$ly      = $ty + ( $th - $logo_draw_h ) / 2 - 8;
			$img_ops = $w->image( $logo_url, $lx, $ly, $logo_draw_w, $logo_draw_h );
			if ( $img_ops ) {
				$ops       .= $img_ops;
				$logo_placed = true;
			}
		}
		if ( ! $logo_placed ) {
			// Fallback: text representation of the Story Lab logo.
			$lcy = $ty + $th / 2 - 20;
			$lcx = $logo_zone_x + $logo_zone_w / 2;
			$ops .= $w->text_centered( $lcx, $lcy,      'Story', 13, true,
				self::C_BLACK_R, self::C_BLACK_G, self::C_BLACK_B );
			$ops .= $w->text_centered( $lcx, $lcy + 16, 'Lab.', 13, true,
				self::C_BLACK_R, self::C_BLACK_G, self::C_BLACK_B );
			$ops .= $w->text_centered( $lcx, $lcy + 28, 'AOTEAROA', 5, false,
				self::C_DGRAY_R, self::C_DGRAY_G, self::C_DGRAY_B );
		}

		// ------------------------------------------------------------------
		// 6. Right stub.
		// ------------------------------------------------------------------
		$stub_cx = $stub_x + $stub_w / 2;

		// Vertical dotted separator.
		$dash_y   = $ty + 6;
		$dash_end = $ty + $th - 6;
		while ( $dash_y + 3 <= $dash_end ) {
			$ops .= $w->rect( $stub_x - 0.75, $dash_y, 0.75, 2.5,
				self::C_LGRAY_R, self::C_LGRAY_G, self::C_LGRAY_B );
			$dash_y += 5.5;
		}

		// "TICKET" in red bold.
		$sy = $ty + 18;
		$ops .= $w->text_centered( $stub_cx, $sy, 'TICKET', 8, true,
			self::C_RED_R, self::C_RED_G, self::C_RED_B );
		$sy += 12;

		// "— X of Y —" in dark gray.
		$seq_line = "\xe2\x80\x94 " . $d['seq'] . ' of ' . $d['quantity'] . " \xe2\x80\x94";
		$ops .= $w->text_centered( $stub_cx, $sy, $seq_line, 8, false,
			self::C_DGRAY_R, self::C_DGRAY_G, self::C_DGRAY_B );
		$sy += 14;

		// Thin separator rule.
		$ops .= $w->hline( $stub_x + 8, $sy, $stub_w - 18,
			self::C_LGRAY_R, self::C_LGRAY_G, self::C_LGRAY_B, 0.5 );
		$sy += 9;

		// Ticket number.
		$ticket_num = 'SL-' . str_pad( $d['order_number'], 6, '0', STR_PAD_LEFT )
		              . '-' . str_pad( $d['seq'], 2, '0', STR_PAD_LEFT );
		$ops .= $w->text_centered( $stub_cx, $sy, $ticket_num, 7, true,
			self::C_BLACK_R, self::C_BLACK_G, self::C_BLACK_B );
		$sy += 14;

		// Barcode.
		$bar_x = $stub_x + 6;
		$bar_w = $stub_w - 14;
		$bar_h = 26;
		$ops .= static::draw_barcode( $w, $bar_x, $sy, $bar_w, $bar_h, $ticket_num );
		$sy += $bar_h + 5;

		// Decorative digits below barcode.
		$hash_chars = str_split( substr( md5( $ticket_num ), 0, 12 ) );
		$bar_digits = implode( '', array_slice( $hash_chars, 0, 6 ) )
		              . ' ' . implode( '', array_slice( $hash_chars, 6, 6 ) );
		$ops .= $w->text_centered( $stub_cx, $sy, strtoupper( $bar_digits ), 6, false,
			self::C_BLACK_R, self::C_BLACK_G, self::C_BLACK_B );

		// Dark maroon accent at the bottom of the stub.
		$ops .= $w->rect( $stub_x, $ty + $th - $btm_h, $stub_w - 2, $btm_h,
			self::C_DKRED_R, self::C_DKRED_G, self::C_DKRED_B );

		// ------------------------------------------------------------------
		// 7. Main content: show name + detail rows.
		// ------------------------------------------------------------------
		$cursor_y = $ty + 18;

		// Show name (large, red, bold).
		$show_name = ! empty( $d['show_name'] ) ? $d['show_name'] : 'Story Lab';
		$name_size = 15;
		while ( $name_size > 8
		        && $w->approx_text_width( $show_name, $name_size, true ) > $content_w ) {
			$name_size--;
		}
		$result   = $w->text_wrap( $content_x, $cursor_y, $show_name, $content_w,
			$name_size, true,
			self::C_RED_R, self::C_RED_G, self::C_RED_B );
		$ops     .= $result['ops'];
		$cursor_y = $result['y'] + 2;

		// Thin red separator line.
		$ops     .= $w->hline( $content_x, $cursor_y, $content_w - 10,
			self::C_RED_R, self::C_RED_G, self::C_RED_B, 0.75 );
		$cursor_y += 10;

		// Detail rows: DATE, TIME, VENUE, NAME.
		// Labels: red, 6pt. Values: black bold, 9pt.
		// Fixed label column width for consistent alignment.
		$label_size  = 6;
		$value_size  = 9;
		$row_h       = 14;
		$label_col_w = 30; // wide enough for 'VENUE' at 6pt

		$details = array();
		if ( ! empty( $d['date'] ) )        { $details[] = array( 'DATE',  $d['date'] ); }
		if ( ! empty( $d['time'] ) )        { $details[] = array( 'TIME',  $d['time'] ); }
		if ( ! empty( $d['location'] ) )    { $details[] = array( 'VENUE', $d['location'] ); }
		if ( ! empty( $d['ticket_name'] ) ) { $details[] = array( 'NAME',  $d['ticket_name'] ); }

		foreach ( $details as $row ) {
			list( $label, $value ) = $row;
			$ops .= $w->text( $content_x, $cursor_y, $label, $label_size, false,
				self::C_RED_R, self::C_RED_G, self::C_RED_B );
			$val_result = $w->text_wrap(
				$content_x + $label_col_w, $cursor_y,
				$value, $content_w - $label_col_w,
				$value_size, true,
				self::C_BLACK_R, self::C_BLACK_G, self::C_BLACK_B,
				$row_h
			);
			$ops      .= $val_result['ops'];
			$cursor_y  = max( $cursor_y + $row_h, $val_result['y'] );
		}

		// Dotted bottom line across the content area.
		$dot_y       = $ty + $th - 10;
		$dot_count   = 32;
		$dot_spacing = ( $stub_x - $content_x - 10 ) / $dot_count;
		for ( $i = 0; $i < $dot_count; $i++ ) {
			$ops .= $w->rect( $content_x + $i * $dot_spacing, $dot_y, 1.2, 1.2,
				self::C_LGRAY_R, self::C_LGRAY_G, self::C_LGRAY_B );
		}

		return $ops;
	}

	// =========================================================================
	// Barcode helper
	// =========================================================================

	/**
	 * Draw a decorative barcode from a seed string.
	 * Uses a deterministic pattern derived from md5($seed).
	 *
	 * @param Storylab_PDF_Writer $w
	 * @param float  $x      Left edge.
	 * @param float  $y      Top edge (from page top).
	 * @param float  $bw     Total available width.
	 * @param float  $bh     Bar height.
	 * @param string $seed   Seed string (e.g. ticket number).
	 * @return string
	 */
	private static function draw_barcode( Storylab_PDF_Writer $w, $x, $y, $bw, $bh, $seed ) {
		$hash = md5( $seed );
		$ops  = '';
		$cx   = (float) $x;
		$end  = (float) ( $x + $bw );

		// Leading guard bars.
		foreach ( array( 1.0, 1.0, 1.0 ) as $gw ) {
			$ops .= $w->rect( $cx, $y, $gw, $bh,
				self::C_BLACK_R, self::C_BLACK_G, self::C_BLACK_B );
			$cx += $gw + 0.8;
		}
		$cx += 1.0;

		// Data bars derived from hash (repeated to fill width).
		$hex_chars = str_split( str_repeat( $hash, 4 ) );
		foreach ( $hex_chars as $ch ) {
			// Reserve space for the trailing guard bars.
			if ( $cx + 6 >= $end ) {
				break;
			}
			$v = hexdec( $ch );
			if ( $v < 5 )      { $barw = 1.0; }
			elseif ( $v < 11 ) { $barw = 1.6; }
			else               { $barw = 2.3; }

			$ops .= $w->rect( $cx, $y, $barw, $bh,
				self::C_BLACK_R, self::C_BLACK_G, self::C_BLACK_B );
			$cx += $barw + 0.8;
		}

		// Trailing guard bars.
		$cx = $end - 4.6;
		foreach ( array( 1.0, 1.0, 1.0 ) as $gw ) {
			if ( $cx >= $end ) {
				break;
			}
			$ops .= $w->rect( $cx, $y, $gw, $bh,
				self::C_BLACK_R, self::C_BLACK_G, self::C_BLACK_B );
			$cx += $gw + 0.8;
		}

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
