/**
 * Project Selector block — editor UI
 *
 * Block name: brighter/project-selector
 * Owned by: site-essentials/Modules/CustomPosts/Projects/
 *
 * Fetches project list from /site-essentials/v1/projects (editor-context, nonce-auth).
 * Modelled on the FAQ Selector block (FAQ/assets/faq-selector-block.js) — pick
 * specific projects by ID so a renamed project title/slug never drops the embed.
 *
 * v1.0 | 2026-08-20
 */
(function (wp) {
	var registerBlockType = wp.blocks.registerBlockType;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var components = wp.components;
	var PanelBody = components.PanelBody;
	var CheckboxControl = components.CheckboxControl;
	var SelectControl = components.SelectControl;
	var TextControl = components.TextControl;
	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useMemo = wp.element.useMemo;
	var __ = wp.i18n.__;

	registerBlockType('brighter/project-selector', {
		title: __('Project Selector', 'site-essentials'),
		description: __('Display selected projects (by ID) as cards, in a horizontal or stacked layout', 'site-essentials'),
		icon: 'portfolio',
		category: 'common',
		attributes: {
			selectedProjects: { type: 'array', default: [] },
			displayFormat: { type: 'string', default: 'horizontal' },
			titleTag: { type: 'string', default: 'h3' },
		},

		edit: function (props) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var selectedProjects = attributes.selectedProjects || [];
			var displayFormat = attributes.displayFormat;
			var titleTag = attributes.titleTag;

			var allProjectsState = useState([]);
			var allProjects = allProjectsState[0];
			var setAllProjects = allProjectsState[1];

			var loadingState = useState(true);
			var loading = loadingState[0];
			var setLoading = loadingState[1];

			var errorState = useState('');
			var error = errorState[0];
			var setError = errorState[1];

			var filterState = useState('');
			var filter = filterState[0];
			var setFilter = filterState[1];

			useEffect(function () {
				wp.apiFetch({ path: '/site-essentials/v1/projects' })
					.then(function (projects) {
						setAllProjects(Array.isArray(projects) ? projects : []);
						setLoading(false);
					})
					.catch(function (err) {
						console.error('Project Selector: error fetching projects', err);
						setError((err && err.message) || __('Could not load projects.', 'site-essentials'));
						setLoading(false);
					});
			}, []);

			function toggleProject(projectId) {
				var newSelected = selectedProjects.indexOf(projectId) !== -1
					? selectedProjects.filter(function (id) { return id !== projectId; })
					: selectedProjects.concat([projectId]);
				setAttributes({ selectedProjects: newSelected });
			}

			function moveUp(index) {
				if (index === 0) return;
				var newSelected = selectedProjects.slice();
				var temp = newSelected[index - 1];
				newSelected[index - 1] = newSelected[index];
				newSelected[index] = temp;
				setAttributes({ selectedProjects: newSelected });
			}

			function moveDown(index) {
				if (index === selectedProjects.length - 1) return;
				var newSelected = selectedProjects.slice();
				var temp = newSelected[index + 1];
				newSelected[index + 1] = newSelected[index];
				newSelected[index] = temp;
				setAttributes({ selectedProjects: newSelected });
			}

			function removeProject(index) {
				var newSelected = selectedProjects.slice();
				newSelected.splice(index, 1);
				setAttributes({ selectedProjects: newSelected });
			}

			var selectedProjectObjects = useMemo(function () {
				return selectedProjects
					.map(function (id) {
						return allProjects.find(function (project) { return project.id === id; });
					})
					.filter(Boolean);
			}, [selectedProjects, allProjects]);

			var filteredProjects = useMemo(function () {
				var q = (filter || '').toLowerCase().trim();
				if (!q) return allProjects;
				return allProjects.filter(function (project) {
					return (project.title || '').toLowerCase().indexOf(q) !== -1;
				});
			}, [allProjects, filter]);

			return el(Fragment, null,
				el(InspectorControls, null,
					el(PanelBody, { title: __('Display Settings', 'site-essentials'), initialOpen: true },
						el(SelectControl, {
							label: __('Layout', 'site-essentials'),
							value: displayFormat,
							options: [
								{ label: __('Horizontal (image left)', 'site-essentials'), value: 'horizontal' },
								{ label: __('Stacked (image top)', 'site-essentials'), value: 'stacked' },
							],
							onChange: function (value) { setAttributes({ displayFormat: value }); },
						}),
						el(SelectControl, {
							label: __('Title Tag', 'site-essentials'),
							value: titleTag,
							options: [
								{ label: 'H2', value: 'h2' },
								{ label: 'H3', value: 'h3' },
								{ label: 'H4', value: 'h4' },
								{ label: 'H5', value: 'h5' },
								{ label: 'H6', value: 'h6' },
								{ label: 'P', value: 'p' },
							],
							onChange: function (value) { setAttributes({ titleTag: value }); },
						})
					),

					el(PanelBody, { title: __('Select Projects', 'site-essentials'), initialOpen: true },
						loading
							? el('p', null, __('Loading projects…', 'site-essentials'))
							: error
								? el('p', { style: { color: '#b32d2e' } }, error)
								: allProjects.length === 0
									? el('p', null, __('No projects found. Create some projects first.', 'site-essentials'))
									: el(Fragment, null,
										el(TextControl, {
											label: __('Filter Projects', 'site-essentials'),
											value: filter,
											onChange: function (value) { setFilter(value); },
											placeholder: __('Search by title…', 'site-essentials'),
										}),
										filteredProjects.length === 0
											? el('p', { style: { color: '#666', fontStyle: 'italic' } },
												__('No projects match your filter.', 'site-essentials'))
											: filteredProjects.map(function (project) {
												return el(CheckboxControl, {
													key: project.id,
													label: project.title,
													checked: selectedProjects.indexOf(project.id) !== -1,
													onChange: function () { toggleProject(project.id); },
												});
											})
									)
					)
				),

				el('div', {
					className: 'bw-project-selector-editor',
					style: { border: '2px dashed #ccc', padding: '20px', borderRadius: '4px', background: '#f9f9f9' },
				},
					el('div', { style: { display: 'flex', alignItems: 'center', marginBottom: '15px' } },
						el('span', { className: 'dashicons dashicons-portfolio', style: { fontSize: '24px', marginRight: '10px' } }),
						el('h3', { style: { margin: 0 } }, __('Project Selector', 'site-essentials'))
					),

					loading
						? el('p', null, __('Loading projects…', 'site-essentials'))
						: error
							? el('p', { style: { color: '#b32d2e' } }, error)
							: selectedProjectObjects.length === 0
								? el('p', { style: { color: '#666', fontStyle: 'italic' } },
									__('No projects selected. Select projects from the sidebar →', 'site-essentials'))
								: el('div', null,
									el('p', { style: { marginBottom: '10px', fontWeight: 'bold' } },
										selectedProjectObjects.length + ' ' + __('project(s) selected', 'site-essentials')
									),
									el('ul', { style: { listStyle: 'none', padding: 0 } },
										selectedProjectObjects.map(function (project, index) {
											return el('li', {
												key: project.id,
												style: {
													padding: '10px',
													marginBottom: '5px',
													background: '#fff',
													border: '1px solid #ddd',
													borderRadius: '4px',
													display: 'flex',
													justifyContent: 'space-between',
													alignItems: 'center',
												},
											},
												el('span', { style: { display: 'flex', alignItems: 'center', gap: '8px' } },
													project.thumbnail && el('img', {
														src: project.thumbnail,
														alt: '',
														style: { width: '32px', height: '32px', objectFit: 'cover', borderRadius: '3px' },
													}),
													el('span', null, (index + 1) + '. ' + project.title)
												),
												el('div', { style: { display: 'flex', gap: '5px' } },
													index > 0 && el('button', {
														onClick: function () { moveUp(index); },
														className: 'button button-small',
														title: __('Move up', 'site-essentials'),
													}, '↑'),
													index < selectedProjectObjects.length - 1 && el('button', {
														onClick: function () { moveDown(index); },
														className: 'button button-small',
														title: __('Move down', 'site-essentials'),
													}, '↓'),
													el('button', {
														onClick: function () { removeProject(index); },
														className: 'button button-small',
														style: { color: '#dc3232' },
														title: __('Remove', 'site-essentials'),
													}, '×')
												)
											);
										})
									),
									el('p', { style: { marginTop: '15px', fontSize: '12px', color: '#666' } },
										__('Layout: ', 'site-essentials') + displayFormat +
										' | ' +
										__('Title tag: ', 'site-essentials') + titleTag.toUpperCase()
									)
								)
				)
			);
		},

		save: function () {
			return null;
		},
	});
})(window.wp);
