jQuery(document).ready(function ($) {

    $("form#login button[name='remind']").on('click', function() {
        console.log();
        $(this).parents("form").find("input[name='password']").prop("required", false);
    });

	/**
	 * Data entry helpers.
	 */
	$("input[data-column-type='datetime']").mask("9999-99-99 99:99:99", { placeholder:"yyyy-mm-dd hh:mm:ss" } );
	$("input[data-column-type='date']").datepicker({ dateFormat: 'yy-mm-dd' });
	$("input[data-column-type='date']").mask("9999-99-99", { placeholder:"yyyy-mm-dd" } );
	$("input[data-column-type='time']").mask("99:99:99", { placeholder:"hh:mm:ss" } );
	$("input[data-column-type='year']").mask("9999");
	$(document.body).on("keyup blur", "input.schema-identifier", function() {
		$(this).val($(this).val().replace(/[^a-zA-Z0-9_ ]/g,'')).change();
		$(this).val($(this).val().replace(/ /g,'_')).change();
		$(this).val($(this).val().toLowerCase());
	});


	/**
	 * Make sure .disabled buttons are properly disabled.
	 */
	$("button.disabled").prop("disabled", true);

});

