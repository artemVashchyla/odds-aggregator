/**
 * Registers the odds table block.
 *
 * The block is dynamic: save() returns null and all markup comes from the
 * PHP render callback, so a published page always reflects current cached
 * odds rather than whatever was stored at save time.
 */

import { registerBlockType } from '@wordpress/blocks';

import metadata from './block.json';
import Edit from './edit';
import './style.scss';
import './editor.scss';

registerBlockType( metadata.name, {
	edit: Edit,
	save: () => null,
} );
