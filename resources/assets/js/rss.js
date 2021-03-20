$(function() {

    $('body.rss-create select.location-lookup, body.rss-edit select.location-lookup').selectize({
        valueField: 'model',
        labelField: 'name',
        searchField: 'name',
        create: false,
        render: {
            option: function(item, escape) {
                return '<option value="' + item.model + '">' + item.name + '</option>';
            }
        },
        load: function(query, callback) {

            if (!query.length) return callback();

            $.ajax({
                url: BASE_URL + '/search/location',
                type: 'POST',
                data: {
                    '_token': $('meta[name="csrf-token"]').attr('content'),
                    'q': query
                },
                error: function() {
                    callback();
                },
                success: function(response) {
                    callback(response);
                }
            });
        }
    });
});