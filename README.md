# webhook

github的webhook是个有用的功能，允许开发人员指定一个服务器的url。
当开发者对github仓库施加操作，比如提交代码，创建issue时，github网站会自动向该url指定的服务器推送事件。
本项目通过建立一个HTTP服务，接收来自Gihub的钩子事件，达到对本地项目自动管理的目的。
目前仅支持更新事件的处理。

##如何实用webhook

**第一步**

从github clone项目下来，并将项目名称与项目路径写入配置文件conf.json中的repositories里,注意设置文件目录的相关读写权限。
建议使用ssh+密钥的方式拉项目，这样后续git pull的时候就不需要输入密码了。

**第二步**

运行webhook
```$xslt
php webhook.php start
```

**第三步** 

将http服务的地址填入github的webhooks配置中，地址格式为http://ip:port，可以设置为仅推送push事件（Just the push event.)


##webhook支持的命令

前台模式运行
```shell
php webhook.php start
```

后台模式运行
所有输出内容，默认会写在./tmp/webhook.log文件里
```shell
php webhook.php start -d
```

停止后台进程
```shell
php webhook.php stop
```

查看服务状态
```shell
php webhook.php status
```

##参考

[GitHub配置SSH key](https://blog.csdn.net/u013778905/article/details/83501204)

