jQuery(document).ready(function($) {
 
  var jobtable = $('#table').DataTable({
    ajax: {
      url: datatablesajax.url + '?action=getpostsfordatatables'
    },
    columns: [
        { data: 'id' },
        { data: 'title' },
        { data: 'author' },
        { data: 'date' },
        { data: 'excerpt' },
        { data: 'link' }
    ],
    columnDefs: [
        {
            "render": function (data, type, row) {
            return '<a href="' + data + '">Read this post</a>';
            },
            "targets": 5
        }
    ]
  });
});
