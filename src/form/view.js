/**
 * Sends forms without a page reload, through the REST route in
 * includes/submit.php. If that fails for any reason other than invalid
 * answers, the form is submitted the normal way instead.
 */
function clearErrors( form ) {
	form.querySelectorAll( '.fbf-field.has-error' ).forEach( ( field ) => field.classList.remove( 'has-error' ) );
	form.querySelectorAll( '[aria-invalid]' ).forEach( ( input ) => {
		input.removeAttribute( 'aria-invalid' );
		input.removeAttribute( 'aria-describedby' );
	} );
	form.querySelectorAll( '.fbf-error' ).forEach( ( error ) => {
		error.textContent = '';
		error.hidden = true;
	} );
}

function showErrors( form, errors ) {
	let first = null;
	Object.entries( errors ).forEach( ( [ name, message ] ) => {
		const field = form.querySelector( `.fbf-field[data-name="${ CSS.escape( name ) }"]` );
		if ( ! field ) {
			return;
		}
		const error = field.querySelector( '.fbf-error' );
		field.classList.add( 'has-error' );
		field.querySelectorAll( 'input, textarea, select' ).forEach( ( input ) => {
			input.setAttribute( 'aria-invalid', 'true' );
			if ( error ) {
				input.setAttribute( 'aria-describedby', error.id );
			}
		} );
		if ( error ) {
			error.textContent = message;
			error.hidden = false;
		}
		first ??= field.querySelector( 'input, textarea, select' );
	} );
	first?.focus();
}

function showMessage( form, message ) {
	const box = form.querySelector( '.fbf-message--error' );
	if ( box ) {
		box.textContent = message;
		box.hidden = ! message;
	}
}

async function submit( event ) {
	const form = event.currentTarget;
	const button = form.querySelector( '.fbf-submit' );
	event.preventDefault();

	if ( form.dataset.sending ) {
		return;
	}
	form.dataset.sending = '1';
	button?.setAttribute( 'disabled', '' );
	button?.setAttribute( 'aria-busy', 'true' );

	try {
		const response = await window.fetch( form.dataset.endpoint, { method: 'POST', body: new window.FormData( form ), credentials: 'same-origin' } );
		const result = await response.json();

		if ( result.ok ) {
			if ( result.redirect ) {
				window.location.assign( result.redirect );
				return;
			}
			const done = document.createElement( 'div' );
			done.className = 'fbf-message fbf-message--success';
			done.setAttribute( 'role', 'status' );
			done.tabIndex = -1;
			done.textContent = result.message;
			form.replaceWith( done );
			done.focus();
			return;
		}

		if ( ! result.message ) {
			throw new Error( 'Unexpected response' );
		}
		clearErrors( form );
		showMessage( form, result.message );
		showErrors( form, result.errors || {} );
	} catch ( error ) {
		// No usable answer from the REST route: let the browser post the form.
		form.removeEventListener( 'submit', submit );
		form.submit();
		return;
	} finally {
		delete form.dataset.sending;
		button?.removeAttribute( 'disabled' );
		button?.removeAttribute( 'aria-busy' );
	}
}

document.querySelectorAll( '.fbf-form[data-endpoint]' ).forEach( ( form ) => {
	form.addEventListener( 'submit', submit );
} );

// After a submission without JavaScript, move to the result.
document.querySelector( '.fbf-form-block .fbf-message:not([hidden])' )?.focus?.();
