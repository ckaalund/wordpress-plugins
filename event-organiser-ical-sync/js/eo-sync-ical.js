(function($) {
			
var eventorganiserFeedManager = {

	init : function(){
		var t = this;
		$('#feed-list').wpList( { 
			delBefore: eventorganiserFeedManager.confirmDelete,
			dimBefore: eventorganiserFeedManager.dimBefore,
			dimAfter: eventorganiserFeedManager.dimAfter,
			addAfter: eventorganiserFeedManager.addAfter,
			delAfter: eventorganiserFeedManager.delAfter
		} );
		
		$('#feed-list').on( 'click', '.row-actions .edit a', function(e){
			e.preventDefault();
			eventorganiserFeedManager.revert();
			eventorganiserFeedManager.showOptions( $(this) );
		});
		
		$('#feed-list').on( 'click', 'td.edit-column .cancel', function(e){
			e.preventDefault();
			eventorganiserFeedManager.revert();
		});
		
		$('#feed-list').on( 'keyup', function(e){
			if ( e.which == 27 )
				return eventorganiserFeedManager.revert();
		});
		
		$('.eo-advanced-feed-options-toggle').click(function(e){
			e.preventDefault();
			if( $(this).is('.eo-show-advanced-option') ){
				$('#eo-advanced-feed-options-wrap').show();
			}else{
				$('#eo-advanced-feed-options-wrap').hide();
			}
			
			$(".eo-advanced-feed-options-toggle").not(this).show();
			$(this).hide();
		});
		
	},

	showOptions: function( o ){
		var row = o.parents('tbody');
		row.addClass('inline-edit-row');
		row.find('td').hide();
		row.find('td.edit-column').show();
	},
	
	revert : function(){
		$('#feed-list td').show();
		$('#feed-list tbody').removeClass('inline-edit-row');
		$('#feed-list td.edit-column').hide();
		
		return false;
	},
	addAfter : function( r, s ){
		var res = wpAjax.parseAjaxResponse(r, s.response, s.element), message;
		if ( !res || res.errors ) {
			if( !res ){
				message = 'Unknown error';
			}else{
				message = res.responses[0].errors[0].message;
			}
			$('#'+s.element+' .feed-errors td').append( '<div class="feed-error feed-alert"/>') .text( message );
		}else{
			$('#eo-feed-no-feeds').hide();
		}
	},
	
	getId : function( o ) {
		var id = $(o).closest('tbody').attr('id'),
		parts = id.split('-');
		return parts[parts.length - 1];
	},
	
	confirmDelete: function( s ){
		if ( confirm( "You are about to permanently delete this feed.\n 'Cancel' to stop, 'OK' to delete." ) ) {
			return s;
		}
		return false;
	},
	
	delAfter: function( r, s ){
		var res = wpAjax.parseAjaxResponse(r, s.response, s.element), message;
		if ( !res || res.errors ) {
			if( !res ){
				message = 'Unknown error';
			}else{
				message = res.responses[0].errors[0].message;
			}
			$('#'+s.element+' .feed-errors td').html( '<div class="feed-error feed-alert"/>').find('.feed-error').text( message );
		}else{
			if( $('#feed-list tbody:visible').length === 0 ){
				$('#eo-feed-no-feeds').show();
			}
		}
	},
	
	dimBefore: function( s ){
		
		if( 'fetch-eo-feed' == s.data.action ){
			if( $(s.target).data('eo-lock') ){
				return false;
			}else{
				$(s.target).data('eo-lock', '1' );
			}
			$(s.target).parents('.row-actions').find('.spinner').css( 'visibility', 'visible' );
			$(s.target).css({ 'color': '#aaa', cursor: 'default' });
		}
		return s;
	},
	
	dimAfter: function( r, s ){
		
		if( 'fetch-eo-feed' == s.data.action ){
			$(s.target).css({ 'color': '', cursor: 'pointer' });
			$(s.target).removeData('eo-lock');
			var res = wpAjax.parseAjaxResponse(r, s.response, s.element), message;
			
			if ( !res || res.errors ) {
				if( !res ){
					message = 'Unknown error';
				}else{
					message = res.responses[0].errors[0].message;
				}
				$('#'+s.element+' .feed-errors td').html( '<div class="feed-error feed-alert"/>').find('.feed-error').text( message );
			}else{
				jQuery.each( res.responses, function() {
					var row = $('#'+this.what+'-'+this.id);
					row.find( 'td.feed-events').text( this.supplemental.events );
					row.find( 'td.last-updated').html( this.supplemental.last_updated );
				} );
				
				if( res.responses[0].supplemental.warnings ){
					var warnings = res.responses[0].supplemental.warnings;
					$('#'+s.element+' .feed-errors td').html( '<div class="feed-warning feed-alert">'+warnings+'</div>');
				}
			}
			
			$(s.target).parents('.row-actions').find('.spinner').css( 'visibility', 'hidden' );
		}
		return s;
	} 
};


$(document).ready(function(){
	eventorganiserFeedManager.init();
});
	
})(jQuery);

