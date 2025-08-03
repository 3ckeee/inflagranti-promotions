jQuery(document).ready(function($){
  var validatedPromoCode = null;
  var promoDiscount = 0;
  
  $('#ifl-subscribe-form').on('submit', function(e){
    e.preventDefault();

    var $form = $(this);
    var $button = $form.find('button[type="submit"]');
    var $message = $('#ifl-subscribe-message');
    var originalButtonText = $button.text();

    var data = {
      action: 'ifl_subscribe',
      nonce:  iflSubscribers.nonce,
      email:  $('#ifl-email').val(),
      name:   $('#ifl-name').val(),
      phone:  $('#ifl-phone').val(),
      promo_code: validatedPromoCode, // Send validated promo code
      promo_discount: promoDiscount   // Send discount amount
    };

    // Show loading state
    $button.prop('disabled', true).text('Registrujeme vás...');
    $message.removeClass('ifl-success ifl-error').empty().hide();

    $.ajax({
      url:      iflSubscribers.ajax_url,
      method:   'POST',
      data:     data,
      dataType: 'json'
    })
    .done(function(res){
      if ( res.success ) {
        var successMessage = '🎉 ' + res.data.message;
        if (res.data.applied_discount && res.data.applied_discount > 5) {
          successMessage += '<br><span style="color: #B8A75D; font-weight: bold;">✨ Promo kód aplikovaný! Vaša zľava: ' + res.data.applied_discount + '%</span>';
        }
        
        $message.html(
          '<div class="ifl-success">'+
            successMessage + '<br><br>' +
            '<a href="'+res.data.download_url+'" target="_blank" style="display: inline-block; background: #B8A75D; color: white; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-weight: 600; margin-top: 8px;">'+
              '📱 Stiahnuť kartu do Apple Wallet'+
            '</a>'+
          '</div>'
        ).fadeIn();
        
        // Clear form after success
        $form[0].reset();
        validatedPromoCode = null;
        promoDiscount = 0;
        $('#ifl-promo-message').hide();
        
        // Scroll to message
        $('html, body').animate({
          scrollTop: $message.offset().top - 20
        }, 500);
        
      } else {
        $message.html(
          '<div class="ifl-error">❌ '+res.data.message+'</div>'
        ).fadeIn();
      }
    })
    .fail(function(jqXHR, textStatus, errorThrown){
      console.error('AJAX failed:', textStatus, errorThrown, jqXHR.responseText);
      $message.html(
        '<div class="ifl-error">❌ Nastala chyba pripojenia. Skúste to prosím znovu.</div>'
      ).fadeIn();
    })
    .always(function(){
      // Restore button state
      $button.prop('disabled', false).text(originalButtonText);
    });
  });
  
  // Promo code validation
  $('#ifl-check-promo').on('click', function() {
    var $button = $(this);
    var $input = $('#ifl-promo');
    var $message = $('#ifl-promo-message');
    var promoCode = $input.val().trim().toUpperCase();
    
    if (!promoCode) {
      showPromoMessage('Zadajte promo kód', 'error');
      return;
    }
    
    $button.prop('disabled', true).text('Overujem...');
    $message.hide();
    
    $.ajax({
      url: iflSubscribers.ajax_url,
      method: 'POST',
      data: {
        action: 'ifl_validate_promo',
        nonce: iflSubscribers.nonce,
        code: promoCode
      },
      dataType: 'json'
    })
    .done(function(res) {
      if (res.success) {
        validatedPromoCode = promoCode;
        promoDiscount = res.data.discount;
        showPromoMessage('✅ ' + res.data.message, 'success');
        $input.prop('readonly', true).css('background-color', '#f0fdf4');
        $button.text('✓').css('background', '#10b981');
      } else {
        validatedPromoCode = null;
        promoDiscount = 0;
        showPromoMessage('❌ ' + res.data.message, 'error');
      }
    })
    .fail(function() {
      showPromoMessage('❌ Chyba pri overovaní kódu', 'error');
    })
    .always(function() {
      if (!validatedPromoCode) {
        $button.prop('disabled', false).text('Overiť');
      }
    });
  });
  
  // Reset promo code when input changes
  $('#ifl-promo').on('input', function() {
    if (validatedPromoCode) {
      validatedPromoCode = null;
      promoDiscount = 0;
      $(this).prop('readonly', false).css('background-color', '');
      $('#ifl-check-promo').text('Overiť').css('background', '').prop('disabled', false);
      $('#ifl-promo-message').hide();
    }
  });
  
  // Auto-uppercase promo code
  $('#ifl-promo').on('input', function() {
    this.value = this.value.toUpperCase();
  });
  
  function showPromoMessage(message, type) {
    var $message = $('#ifl-promo-message');
    $message.removeClass('success error').addClass(type).html(message).fadeIn();
  }
  
  // Enhanced form validation with real-time feedback
  $('#ifl-email').on('blur', function() {
    var email = $(this).val();
    var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    
    if (email && !emailRegex.test(email)) {
      $(this).css('border-color', '#ef4444');
    } else {
      $(this).css('border-color', '');
    }
  });

  // Phone number formatting
  $('#ifl-phone').on('input', function() {
    var value = $(this).val().replace(/\D/g, '');
    var formattedValue = value;
    
    if (value.startsWith('421')) {
      formattedValue = '+421 ' + value.substring(3);
    } else if (value.startsWith('0')) {
      formattedValue = '+421 ' + value.substring(1);
    } else if (value.length > 0 && !value.startsWith('421')) {
      formattedValue = '+421 ' + value;
    }
    
    // Add spacing for readability
    if (formattedValue.length > 8) {
      formattedValue = formattedValue.substring(0, 8) + ' ' + 
                      formattedValue.substring(8, 11) + ' ' + 
                      formattedValue.substring(11);
    }
    
    $(this).val(formattedValue);
  });
});