jQuery(document).ready(function($) {
    var emailCaptured = false;

    // Escuta o campo nativo, mas também qualquer input do tipo "email" ou classes customizadas
    $('input[type="email"], #billing_email, .checkout-email-field, .ywraq-email').on('blur', function() {
        var email = $(this).val();
        
        // Validação básica do formato de e-mail
        var emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        
        // Se o e-mail for válido e ainda não tiver sido capturado nesta sessão
        if (emailPattern.test(email) && !emailCaptured) {
            $.post(mwc_ajax.url, {
                action: 'mwc_capture_checkout_email',
                nonce: mwc_ajax.nonce,
                email: email
            }, function(response) {
                if (response.success) {
                    emailCaptured = true; // Trava para não enviar requisições duplicadas
                }
            });
        }
    });
});