( function ( blocks, element, components, blockEditor, serverSideRender ) {
	var el = element.createElement;
	var SelectControl = components.SelectControl;
	var Placeholder = components.Placeholder;
	var ServerSideRender = serverSideRender; // wp.serverSideRender (default export)
	var useBlockProps = ( blockEditor && blockEditor.useBlockProps ) ? blockEditor.useBlockProps : function () { return {}; };

	var data = window.m1616BlockData || { tables: [] };

	blocks.registerBlockType( 'm1616/price-table', {
		apiVersion: 2,
		title: 'iPhone価格テーブル',
		description: '管理画面で作った価格テーブルを選んで挿入します。',
		icon: 'smartphone',
		category: 'widgets',
		attributes: {
			tableId: { type: 'string', default: '' }
		},
		edit: function ( props ) {
			var blockProps = useBlockProps();
			var options = [ { label: '— テーブルを選択 —', value: '' } ].concat(
				( data.tables || [] ).map( function ( t ) {
					return { label: t.label, value: t.value };
				} )
			);

			var selector = el( SelectControl, {
				label: '価格テーブル',
				value: props.attributes.tableId,
				options: options,
				onChange: function ( v ) { props.setAttributes( { tableId: v } ); }
			} );

			var body;
			if ( props.attributes.tableId ) {
				body = el( ServerSideRender, {
					block: 'm1616/price-table',
					attributes: props.attributes
				} );
			} else {
				body = el( 'p', { style: { color: '#777' } }, '上のメニューから表示するテーブルを選んでください。' );
			}

			return el(
				'div',
				blockProps,
				el( Placeholder, { icon: 'smartphone', label: 'iPhone価格テーブル' }, selector ),
				body
			);
		},
		save: function () {
			return null; // 動的ブロック（サーバー側でレンダリング）
		}
	} );
} )(
	window.wp.blocks,
	window.wp.element,
	window.wp.components,
	window.wp.blockEditor,
	window.wp.serverSideRender
);
