# Frankas Block Forms

Forms for WordPress, built from blocks. A form is a post with Form Field blocks as its content, plus any text or layout blocks around them. You place it on a page with the Form block and can edit its fields right there, in the page editor.

- **Fields:** text, email, phone, number, date, text area, dropdown, radio buttons and checkbox. Fields can be required.
- **Settings per form:**
  - button text;
  - thank-you message, or a page to go to after sending;
  - whether submissions are saved;
  - an e-mail to you for every submission;
  - a confirmation e-mail to the person who filled in the form.
- **Submissions** are listed under *Forms › Submissions*, per form.
- **Templates:** save a form as a template and start new forms from it.
- **Accessible:**
  - native browser validation;
  - server-side errors linked to their fields;
  - focus moves to the first error.

  Forms also work without JavaScript.
- **Spam protection without outside services:** a hidden field and a minimum fill-in time. There's no nonce, so forms keep working on cached pages.
- **Translations:** English and Dutch (`nl_NL`).

## Requirements

- WordPress 6.6 or later
- PHP 8.1 or later
- To build from source: Node.js 18 or later and npm

## Installation

The repository contains the source code. The compiled blocks (`build/`) aren't committed, so build them once after downloading.

1. Clone the plugin into your plugins folder:

   ```sh
   cd wp-content/plugins
   git clone https://github.com/Baeliale/frankas-block-forms.git
   ```

   Or download the ZIP from GitHub, unpack it in `wp-content/plugins` and rename the folder to `frankas-block-forms`.

2. Build it:

   ```sh
   cd frankas-block-forms
   npm install
   npm run build
   ```

3. In WordPress, go to *Plugins* and activate **Frankas Block Forms**.

To update, run `git pull` and then `npm run build` again.

## Usage

1. Go to *Forms › Add form*. A new form starts with Name, Email address and Message fields.
2. Add, remove or reorder fields. Pick each field's type and options in the block sidebar.
3. Set up the button, thank-you message and e-mails under *Form settings* in the sidebar, then save.
4. On any page, add the **Form** block and choose your form.

E-mails are sent with `wp_mail()`. On many hosts you'll need an SMTP plugin for them to arrive reliably.

## Styling

The forms have a neutral look. To match your theme, set these custom properties on `:root` or `.fbf-form`:

```css
:root {
	--fbf-accent: #2c7c84;        /* button, focus and checked colour */
	--fbf-accent-hover: #16434a;
	--fbf-text: #0e2e31;
	--fbf-border: #cfe3dd;
	--fbf-border-hover: #46aab4;
	--fbf-background: #fff;       /* inputs */
	--fbf-focus-ring: #e3f4f5;
	--fbf-error: #c62828;
	--fbf-error-background: #fdecea;
	--fbf-success: #16434a;
	--fbf-success-background: #e3f4f5;
	--fbf-radius: 8px;
	--fbf-label-size: 15px;
	--fbf-input-size: 16px;       /* 16px stops iOS zooming in on focus */
	--fbf-field-gap: 20px;
	--fbf-button-text: #fff;
	--fbf-button-width: auto;     /* or 100% */
}
```

You can also style the `fbf-*` classes directly.

## Developer hooks

**`frankas_block_forms_allowed_blocks`** (filter): the blocks allowed inside a form.

```php
add_filter( 'frankas_block_forms_allowed_blocks', function ( array $blocks ) {
	$blocks[] = 'core/image';
	return $blocks;
} );
```

**`frankas_block_forms_submitted`** (action): fires after a submission is accepted.

```php
add_action( 'frankas_block_forms_submitted', function ( int $form_id, array $data, int $submission_id ) {
	// $data: [ field_name => [ 'label' => …, 'value' => … ] ]
	// $submission_id is 0 when the form doesn't save submissions.
}, 10, 3 );
```

## Development

```sh
npm start          # rebuild on every change
npm run build      # production build
npm run lint:js
npm run i18n       # update the .pot, merge into .po files, compile .mo/.json
```

## Licence

[GPL-2.0-or-later](LICENSE), the same licence as WordPress.
