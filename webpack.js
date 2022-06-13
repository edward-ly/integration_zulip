const path = require('path')
const webpackConfig = require('@nextcloud/webpack-vue-config')
const ESLintPlugin = require('eslint-webpack-plugin')
const StyleLintPlugin = require('stylelint-webpack-plugin')

const buildMode = process.env.NODE_ENV
const isDev = buildMode === 'development'
webpackConfig.devtool = isDev ? 'cheap-source-map' : 'source-map'

webpackConfig.stats = {
	colors: true,
	modules: false,
}

webpackConfig.entry = {
	personalSettings: { import: path.join(__dirname, 'src', 'personalSettings.js'), filename: 'integration_mattermost-personalSettings.js' },
	adminSettings: { import: path.join(__dirname, 'src', 'adminSettings.js'), filename: 'integration_mattermost-adminSettings.js' },
	dashboard: { import: path.join(__dirname, 'src', 'dashboard.js'), filename: 'integration_mattermost-dashboard.js' },
}

webpackConfig.plugins.push(
	new ESLintPlugin({
		extensions: ['js', 'vue'],
		files: 'src',
		failOnError: !isDev,
	})
)
webpackConfig.plugins.push(
	new StyleLintPlugin({
		files: 'src/**/*.{css,scss,vue}',
		failOnError: !isDev,
	}),
)

module.exports = webpackConfig
