/* global wp, wpqrAdmin */
( function () {
	'use strict';

	// Mostra solo i pannelli pertinenti alla modalità scelta (data-wpqr-modes="redirect theme ...").
	function syncPanels() {
		var checked = document.querySelector( 'input[name="wpqr_mode"]:checked' );
		var mode = checked ? checked.value : '';
		document.querySelectorAll( '[data-wpqr-modes]' ).forEach( function ( panel ) {
			var modes = panel.getAttribute( 'data-wpqr-modes' ).split( ' ' );
			panel.hidden = modes.indexOf( mode ) === -1;
		} );
	}

	document.querySelectorAll( 'input[name="wpqr_mode"]' ).forEach( function ( radio ) {
		radio.addEventListener( 'change', syncPanels );
	} );
	syncPanels();

	// Selettore degli allegati: la lista visibile e il campo nascosto (ID separati da virgola) restano allineati.
	var list = document.getElementById( 'wpqr-picker-list' );
	var field = document.getElementById( 'wpqr_attachments' );
	var addButton = document.getElementById( 'wpqr-picker-add' );

	function syncField() {
		var ids = [];
		list.querySelectorAll( '.wpqr-picker__item' ).forEach( function ( item ) {
			ids.push( item.getAttribute( 'data-id' ) );
		} );
		field.value = ids.join( ',' );
	}

	function addItem( id, title ) {
		if ( list.querySelector( '[data-id="' + id + '"]' ) ) {
			return;
		}
		var item = document.createElement( 'li' );
		item.className = 'wpqr-picker__item';
		item.setAttribute( 'data-id', id );

		var name = document.createElement( 'span' );
		name.className = 'wpqr-picker__name';
		name.textContent = title; // textContent: il titolo è testo, non HTML.

		var remove = document.createElement( 'button' );
		remove.type = 'button';
		remove.className = 'button-link wpqr-picker__remove';
		remove.textContent = wpqrAdmin.remove;

		item.appendChild( name );
		item.appendChild( remove );
		list.appendChild( item );
	}

	if ( list && field && addButton ) {
		list.addEventListener( 'click', function ( event ) {
			if ( event.target.classList.contains( 'wpqr-picker__remove' ) ) {
				event.target.closest( '.wpqr-picker__item' ).remove();
				syncField();
			}
		} );

		var frame;
		addButton.addEventListener( 'click', function () {
			if ( ! frame ) {
				frame = wp.media( {
					title: wpqrAdmin.chooseTitle,
					button: { text: wpqrAdmin.chooseButton },
					multiple: true,
				} );
				frame.on( 'select', function () {
					frame.state().get( 'selection' ).each( function ( attachment ) {
						addItem( String( attachment.get( 'id' ) ), attachment.get( 'title' ) || attachment.get( 'filename' ) );
					} );
					syncField();
				} );
			}
			frame.open();
		} );
	}

	// Azzeramento delle statistiche: è un link, quindi basta annullare il click per non procedere.
	var resetLink = document.querySelector( '.wpqr-stats__reset' );
	if ( resetLink ) {
		resetLink.addEventListener( 'click', function ( event ) {
			if ( ! window.confirm( wpqrAdmin.confirmReset ) ) {
				event.preventDefault();
			}
		} );
	}

	// Copia dell'URL pubblico del QR.
	var copyButton = document.getElementById( 'wpqr-copy-url' );
	var urlField = document.getElementById( 'wpqr-code-url' );
	if ( copyButton && urlField ) {
		copyButton.addEventListener( 'click', function () {
			urlField.select();
			if ( navigator.clipboard ) {
				navigator.clipboard.writeText( urlField.value );
			} else {
				document.execCommand( 'copy' );
			}
			var label = copyButton.textContent;
			copyButton.textContent = wpqrAdmin.copied;
			setTimeout( function () {
				copyButton.textContent = label;
			}, 1500 );
		} );
	}
}() );
