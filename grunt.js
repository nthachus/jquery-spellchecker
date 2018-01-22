/*global module:false*/

module.exports = function(grunt) {

  // Project configuration
  grunt.initConfig({
    pkg: '<json:package.json>',
    meta: {
      banner: '/*\n * <%= pkg.title || pkg.name %> - v<%= pkg.version %> - ' +
        '<%= grunt.template.today("yyyy-mm-dd") %>\n' +
        ' * <%= pkg.homepage %>\n' +
        ' * Copyright (c) <%= grunt.template.today("yyyy") %> <%= pkg.author.name %>;' +
        ' Licensed <%= pkg.license %>\n */'
    },
    concat: {
      dist: {
        src: [
          'node_modules/findandreplacedomtext/src/findAndReplaceDOMText.js',
          '<banner:meta.banner>',
          '<file_strip_banner:src/js/<%= pkg.name %>.js>'
        ],
        dest: 'dist/js/<%= pkg.name %>.js'
      }
    },
    copy: {
      dist: {
        files: {
          "dist/css/": "src/css/*"
        }
      }
    },
    min: {
      dist: {
        src: ['<banner:meta.banner>', '<config:concat.dist.dest>'],
        dest: 'dist/js/<%= pkg.name %>.min.js'
      }
    },
    mincss: {
      compress: {
        files: {
          "dist/css/<%= pkg.name %>.min.css": ["src/css/*.css"]
        }
      }
    },
    jasmine : {
      src : [
        'node_modules/jquery/dist/jquery.min.js',
        'src/js/**/*.js'
      ],
      specs : 'tests/javascript/spec/**/*.js'
    },
    'jasmine-server': {
      browser: false
    },
    lint: {
      files: ['grunt.js', 'src/js/**/*.js', 'tests/javascript/**/*.js']
    },
    jshint: {
      options: {
        curly: false,
        eqeqeq: true,
        immed: true,
        latedef: true,
        newcap: true,
        noarg: true,
        sub: true,
        undef: true,
        boss: true,
        eqnull: true,
        browser: true
      },
      globals: {
        jQuery: true,
        $: true,
        module: false,
        require: false,
        alert: false
      }
    },
    uglify: {}
  });

  // Load the plugins
  grunt.loadNpmTasks('grunt-contrib-copy');
  grunt.loadNpmTasks('grunt-contrib-mincss');
  grunt.loadNpmTasks('grunt-jasmine-runner');

  // Default task(s)
  grunt.registerTask('default', 'lint concat min mincss copy');//lint jasmine
};
