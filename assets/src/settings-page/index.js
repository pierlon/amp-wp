/**
 * External dependencies
 */
import PropTypes from 'prop-types';
import {
	CURRENT_THEME,
	HAS_DEPENDENCY_SUPPORT,
	OPTIONS_REST_PATH,
	READER_THEMES_REST_PATH,
	UPDATES_NONCE,
} from 'amp-settings';

/**
 * WordPress dependencies
 */
import domReady from '@wordpress/dom-ready';
import { render, useContext, useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import '../css/variables.css';
import '../css/elements.css';
import '../css/core-components.css';
import './style.css';
import { OptionsContextProvider, Options } from '../components/options-context-provider';
import { ReaderThemesContextProvider, ReaderThemes } from '../components/reader-themes-context-provider';
import { SiteSettingsProvider } from '../components/site-settings-provider';
import { Loading } from '../components/loading';
import { UnsavedChangesWarning } from '../components/unsaved-changes-warning';
import { ErrorContextProvider } from '../components/error-context-provider';
import { AMPDrawer } from '../components/amp-drawer';
import { AMPNotice, NOTICE_SIZE_LARGE } from '../components/amp-notice';
import { Welcome } from './welcome';
import { TemplateModes } from './template-modes';
import { SupportedTemplates } from './supported-templates';
import { MobileRedirection } from './mobile-redirection';
import { SettingsFooter } from './settings-footer';
import { PluginSuppression } from './plugin-suppression';
import { Analytics } from './analytics';
import { PairedUrlStructure } from './paired-url-structure';

const { ajaxurl: wpAjaxUrl } = global;

/**
 * Context providers for the settings page.
 *
 * @param {Object} props Component props.
 * @param {any} props.children Context consumers.
 */
function Providers( { children } ) {
	return (
		<SiteSettingsProvider>
			<OptionsContextProvider optionsRestPath={ OPTIONS_REST_PATH } populateDefaultValues={ true }>
				<ReaderThemesContextProvider
					currentTheme={ CURRENT_THEME }
					readerThemesRestPath={ READER_THEMES_REST_PATH }
					hideCurrentlyActiveTheme={ true }
					updatesNonce={ UPDATES_NONCE }
					wpAjaxUrl={ wpAjaxUrl }
				>
					{ children }
				</ReaderThemesContextProvider>
			</OptionsContextProvider>
		</SiteSettingsProvider>
	);
}
Providers.propTypes = {
	children: PropTypes.any,
};

/**
 * Scrolls to the first focusable element in a section, or to the section if no focusable elements are found.
 *
 * @param {string} focusedSectionId A section ID.
 */
function scrollFocusedSectionIntoView( focusedSectionId ) {
	if ( ! focusedSectionId ) {
		return;
	}

	const focusedSectionElement = document.getElementById( focusedSectionId );
	if ( ! focusedSectionElement ) {
		return;
	}

	focusedSectionElement.scrollIntoView();

	const firstInput = focusedSectionElement.querySelector( 'input, select, textarea, button' );
	if ( firstInput ) {
		firstInput.focus();
	}
}

/**
 * Settings page application root.
 *
 * @param {Object} props
 * @param {Element} props.appRoot App root.
 */
function Root( { appRoot } ) {
	const [ focusedSection, setFocusedSection ] = useState( global.location.hash.replace( /^#/, '' ) );

	const { fetchingOptions, saveOptions } = useContext( Options );
	const { templateModeWasOverridden } = useContext( ReaderThemes );

	/**
	 * Scroll to the focused element on load or when it changes.
	 */
	useEffect( () => {
		if ( fetchingOptions || null === templateModeWasOverridden ) {
			return;
		}

		scrollFocusedSectionIntoView( focusedSection );
	}, [ fetchingOptions, focusedSection, templateModeWasOverridden ] );

	/**
	 * Resets the focused element state when the hash changes on the page.
	 */
	useEffect( () => {
		const hashChangeCallback = ( event = null ) => {
			if ( event ) {
				event.preventDefault();
			}

			// Ensure this runs after state updates.
			const newFocusedSection = global.location.hash.replace( /^#/, '' );
			setFocusedSection( newFocusedSection );
		};

		hashChangeCallback();
		global.addEventListener( 'hashchange', hashChangeCallback );

		return () => {
			global.removeEventListener( 'hashchange', hashChangeCallback );
		};
	}, [ fetchingOptions ] );

	if ( false !== fetchingOptions || null === templateModeWasOverridden ) {
		return <Loading />;
	}

	return (
		<>
			{ ! HAS_DEPENDENCY_SUPPORT && (
				<AMPNotice className="not-has-dependency-support" size={ NOTICE_SIZE_LARGE }>
					{ __( 'You are using an old version of WordPress. Please upgrade to access all of the features of the AMP plugin.', 'amp' ) }
				</AMPNotice>
			) }
			<Welcome />
			<form onSubmit={ ( event ) => {
				event.preventDefault();
				saveOptions();
			} }>
				<TemplateModes focusReaderThemes={ 'reader-themes' === focusedSection } />
				<h2 id="advanced-settings">
					{ __( 'Advanced Settings', 'amp' ) }
				</h2>
				<MobileRedirection id="mobile-redirection" />
				<AMPDrawer

					heading={ (
						<h3>
							{ __( 'Supported Templates', 'amp' ) }
						</h3>
					) }
					hiddenTitle={ __( 'Supported templates', 'amp' ) }
					id="supported-templates"
					initialOpen={ 'supported-templates' === focusedSection }
				>
					<SupportedTemplates />
				</AMPDrawer>
				<AMPDrawer
					heading={ (
						<h3>
							{ __( 'Plugin Suppression', 'amp' ) }
						</h3>
					) }
					hiddenTitle={ __( 'Plugin suppression', 'amp' ) }
					id="plugin-suppression"
					initialOpen={ 'plugin-suppression' === focusedSection }
				>
					<PluginSuppression />
				</AMPDrawer>
				<AMPDrawer
					className="amp-analytics"
					heading={ (
						<h3>
							{ __( 'Analytics', 'amp' ) }
						</h3>
					) }
					hiddenTitle={ __( 'Analytics', 'amp' ) }
					id="analytics-options"
					initialOpen={ 'analytics-options' === focusedSection }
				>
					<Analytics />
				</AMPDrawer>
				<PairedUrlStructure focusedSection={ focusedSection } />
				<SettingsFooter />
			</form>
			<UnsavedChangesWarning excludeUserContext={ true } appRoot={ appRoot } />
		</>
	);
}
Root.propTypes = {
	appRoot: PropTypes.instanceOf( global.Element ),
};

domReady( () => {
	const root = document.getElementById( 'amp-settings-root' );

	if ( root ) {
		render( (
			<ErrorContextProvider>
				<Providers>
					<Root appRoot={ root } />
				</Providers>
			</ErrorContextProvider>
		), root );
	}
} );
